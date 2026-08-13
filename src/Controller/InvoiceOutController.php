<?php

namespace Drupal\erp_e_invoice\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Drupal\e_invoice\InvoiceInterface;
use Drupal\e_invoice\Service\GetConfigInvoice;
use Drupal\e_invoice\Service\HandleInvoice;
use Drupal\erp_e_invoice\InvoiceService;

/**
 * Returns responses for ERPCons accountant out invoice routes.
 */
class InvoiceOutController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    protected GetConfigInvoice $config,
    protected HandleInvoice $handleInvoice,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected UuidInterface $uuid,
    protected InvoiceService $invoiceService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get("e_invoice.get_config"),
      $container->get("e_invoice.handle_invoice"),
      $container->get("file_url_generator"),
      $container->get("entity_field.manager"),
      $container->get("uuid"),
      $container->get("erp_e_invoice.invoice_service"),
    );
  }

  /**
   * Hóa đơn đầu ra.
   */
  public function listInvoiceOut(Request $request) {
    $data = [];
    $node_invoices = $this->invoiceService->getInvoice($request, "output_invoices");

    /** @var \Drupal\field\Entity\FieldConfig $field_config */
    $field_config = $this->entityFieldManager->getFieldDefinitions("invoice", "output_invoices")["field_invoice_status"];
    $allowed_values = $field_config->getFieldStorageDefinition()->getSetting("allowed_values");

    /** @var \Drupal\e_invoice\Entity\Invoice $invoice */
    foreach ($node_invoices["invoices"] as $invoice) {
      $status_value = $invoice->get("field_invoice_status")->value;
      $invoice_status = $allowed_values[$status_value] ?? "";


      /** @var \Drupal\file\Entity\File $invoice_pdf */
      $invoice_pdf = $invoice->get("field_invoice_pdf")->entity ?? NULL;
      if ($invoice_pdf) {
        $invoice_pdf = $this->fileUrlGenerator->generateAbsoluteString($invoice_pdf->getFileUri());
      }

      $data[] = [
        "uuid" => $invoice->uuid(),
        "invoice_name" => $invoice->label(),
        "invoice_issue" => $invoice->get("field_invoice_issue")->value,
        "invoice_no" => $invoice->get("field_invoice_no")->value,
        "invoice_date" => $invoice->get("field_invoice_date")->value,
        "invoice_buyer" => $invoice->get("field_invoice_buyer_name")->value,
        "invoice_buyer_taxcode" => $invoice->get("field_invoice_buyer_taxcode")->value,
        "invoice_vat_amount" => $invoice->get("field_invoice_vat_amount")->value,
        "invoice_discount_amount" => $invoice->get("field_invoice_discount_amount")->value,
        "invoice_amount_without_vat" => $invoice->get("field_invoice_amount_without_vat")->value,
        "invoice_total_amount" => $invoice->get("field_invoice_total_amount")->value,
        "invoice_export" => $invoice->get("field_invoice_export")->value ?? 0,
        "invoice_xml" =>$invoice->get("field_invoice_is_xml")->value ?? 0,
        "invoice_pdf" => $invoice_pdf,
        "invoice_status" => [
          "value" => $status_value,
          "label" => $invoice_status,
        ],
        "invoice_company_id" => $invoice->hasField("field_invoice_company")
          ? $invoice->get("field_invoice_company")->target_id
          : NULL,
      ];
    }

    return [
      "#theme" => "list_output_invoice",
      "#data" => $data,
      "#filter" => [
        "date" => [
          "start" => $node_invoices["date"]["start"] ? date("Y-m-d", strtotime($node_invoices["date"]["start"])) : NULL,
          "end" => $node_invoices["date"]["end"] ? date("Y-m-d", strtotime($node_invoices["date"]["end"])) : NULL,
        ],
        "count_export" => $node_invoices["count_export"],
        "company_id" => $node_invoices["company_id"],
        "status" => $node_invoices["status"],
        "option_company" => $node_invoices["option_company"],
        "option_status" => $allowed_values,
        "destination" => $request->getRequestUri(),
        "current_user" => $this->currentUser()->getAccountName(),
        "pager" => [
          "#type" => "pager",
        ],
      ],
      "#attached" => [
        "library" => [
          "erp_e_invoice/e_invoice",
        ],
      ],
      "#cache" => ["max-age" => 0],
    ];
  }

  /**
   * Tạo hóa đơn nháp.
   */
  public function createInvoice(Request $request, string|int $order_id) {
    $items = [];
    $data = $request->request->all();

    $redirect = $this->invoiceService->safeRedirect(
      $data["destination"] ?? NULL,
      "erp_e_invoice.e_invoice_list_out"
    );

    /** @var \Drupal\node\Entity\Node $order_entity */
    $order_entity = $this->entityTypeManager()
      ->getStorage("node")
      ->load($order_id);

    if (empty($order_entity) || $order_entity->get("field_invoice_issue")->value == "1") {
      $this->messenger()->addError($this->t("Not found issuse order"));
      return new RedirectResponse($redirect);
    }

    /** @var \Drupal\taxonomy\Entity\Term $company_entity */
    $company_entity = $order_entity->get("field_company")->entity;
    if (empty($company_entity)) {
      $this->messenger()->addError($this->t("Not found company"));
      return new RedirectResponse($redirect);
    }

    $order_items = $this->entityTypeManager()
      ->getStorage("paragraph")
      ->loadMultiple(
        array_column(
          $order_entity->get("field_preorder_content")->getValue(),
          "target_id"
        )
      );

    $list_taxs = $this->invoiceService->loadTerm("tax","id", "field_tax_number");
    $list_units = $this->invoiceService->loadTerm("unit", "id");

    /** @var \Drupal\paragraphs\Entity\Paragraph $item */
    foreach ($order_items as $item) {
      $supplies = $item->get("field_cpre_name")->entity;
      // Dòng đơn hàng chưa gắn vật tư thì không đưa lên hóa đơn được.
      if (empty($supplies)) {
        continue;
      }

      $item_vat = $list_taxs[$item->get("field_cpre_tax")->target_id] ?? NULL;
      $item_unit = $list_units[$item->get("field_cpre_unit")->target_id] ?? NULL;

      $item_code = $item->get("field_cpre_code")->value ?? NULL;
      $item_quantity = $item->get("field_cpre_quantity")->value ?? 0;
      $item_price = $item->get("field_cpre_price")->value ?? 0;
      $item_amount = $item->get("field_cpre_money")->value ?? 0;
      $item_discount_rate = $item->get("field_cpre_discounts")->value ?? 0;
      $item_discount_amount = $item->get("field_cpre_discount_value")->value ?? 0;
      $item_vat_amount = $item->get("field_cpre_tax_money")->value ?? 0;

      $item_amount_without_vat = $item_amount - $item_discount_amount;
      $item_total_amount = $item_amount_without_vat + $item_vat_amount;

      $items[] = [
        "item_id" => $supplies->id(),
        "item_code" => $item_code,
        "item_name" => $supplies->label(),
        "item_unit" => $item_unit,
        "item_quantity" => $item_quantity,
        "item_price" => $item_price,
        "item_amount" => $item_amount,
        "item_discount_rate" => $item_discount_rate,
        "item_discount_amount" => $item_discount_amount,
        "item_amount_without_vat" => $item_amount_without_vat,
        "item_vat_rate" => $item_vat,
        "item_vat_amount" => $item_vat_amount,
        "item_total_amount" => $item_total_amount,
        "item_type" => 1,
      ];
    }

    $customer = $order_entity->get("field_order_customer")->entity;
    if (empty($customer)) {
      $this->messenger()->addError($this->t("Not found customer"));
      return new RedirectResponse($redirect);
    }

    $customer_legal = $customer->get("field_customer_company_name")->value ?? "Khách vãng lai";
    $customer_name = $customer->label() ?? "Khách hàng";
    $customer_phone = $customer->get("field_phone_number")->value ?? NULL;
    $customer_taxcode = $customer->get("field_taxcode")->value ?? NULL;
    $customer_address = $order_entity->get("field_preorder_address")->address_line1 ?? $customer->get("field_address")->address_line2;

    $label = $order_entity->get("field_order_invoice_number")->value ?? "Hóa đơn giá trị gia tăng";
    $amount = (float) ($order_entity->get("field_line_excl_tax")->value ?? 0);
    $discount_amount = (float) ($order_entity->get("field_order_total_discount")->value ?? 0);
    $vat_amount = (float) ($order_entity->get("field_order_total_tax")->value ?? 0);
    $total_amount = (float) ($order_entity->get("field_line_incl_tax")->value ?? ($amount - $discount_amount + $vat_amount));

    $this->entityTypeManager()
      ->getStorage("invoice")
      ->create([
        "label" => $label,
        "bundle" => "output_invoices",
        "field_invoice_status" => 0,
        "field_invoice_date" => date("Y-m-d"),
        "field_invoice_buyer_legal" => $customer_legal,
        "field_invoice_buyer_name" => $customer_name,
        "field_invoice_buyer_phone" => $customer_phone,
        "field_invoice_buyer_taxcode" => $customer_taxcode,
        "field_invoice_buyer_address" => $customer_address,
        "field_invoice_amount" => $amount,
        "field_invoice_discount_amount" => $discount_amount,
        "field_invoice_amount_without_vat" => $amount - $discount_amount,
        "field_invoice_vat_amount" => $vat_amount,
        "field_invoice_total_amount" => $total_amount,
        "field_invoice_items" => $items,
        "field_invoice_payment" => "inv_tm_ck",
        "field_invoice_company" => $company_entity->id(),
        "field_invoice_origin" => $order_entity->id(),
      ]
    )
    ->save();

    $this->messenger()->addStatus($this->t("Create invoice successfully"));
    return new RedirectResponse($redirect);
  }

  /**
   * Nhập file hóa đơn đã phát hành.
   */
  public function importInvoice(Request $request) {
    $data = $request->request->all();

    $redirect = $this->invoiceService->safeRedirect(
      $data["destination"] ?? NULL,
      "erp_e_invoice.e_invoice_list_out"
    );

    $uploadedFiles = $request->files->get("xml_files");

    if (empty($uploadedFiles)) {
      $this->messenger()->addError($this->t("Not found file XML"));
      return new RedirectResponse($redirect);
    }

    foreach ($uploadedFiles as $uploadedFile) {
      if ($uploadedFile->getClientOriginalExtension() !== "xml") {
        $this->messenger()->addError($this->t("File must be XML"));
        continue;
      }

      $items = [];
      $data = $this->parseXML($uploadedFile->getRealPath());

      // File XML sai cấu trúc thì bỏ qua, không để cảnh báo undefined key.
      if (empty($data["DLHDon"]["TTChung"]) || empty($data["DLHDon"]["NDHDon"])) {
        $this->messenger()->addError($this->t("Invalid XML structure: @file", [
          "@file" => $uploadedFile->getClientOriginalName(),
        ]));
        continue;
      }

      $invoice_id = $data["DLHDon"]["@attributes"]["Id"] ?? NULL;
      $ttchung = $data["DLHDon"]["TTChung"];
      $ndhd = $data["DLHDon"]["NDHDon"];

      $list_item = $ndhd["DSHHDVu"]["HHDVu"] ?? [];
      if (isset($list_item["THHDVu"])) {
        $list_item = [$list_item];
      }

      foreach ($list_item as $item) {
        $item_quantity = (float) $item["SLuong"];
        $item_price = (float) $item["DGia"];
        $item_amount = (float) $item["ThTien"];
        $item_discount_rate = $item["TLCKhau"];
        $item_discount_amount = (float) $item["STCKhau"];
        $item_vat_rate = (float) str_replace("%", "", $item["TSuat"]);
        $item_type = $item["TChat"];

        $item_amount_without_vat = $item_amount - $item_discount_amount;
        $item_vat_amount = $item_amount_without_vat * $item_vat_rate / 100;
        $item_total_amount = $item_amount_without_vat + $item_vat_amount;

        $items[] = [
          "item_code" => $item["MHHDVu"],
          "item_name" => $item["THHDVu"],
          "item_unit" => !empty($item["DVTinh"]) ? $item["DVTinh"] : "",
          "item_quantity" => $item_quantity,
          "item_price" => $item_price,
          "item_amount" => $item_amount,
          "item_discount_rate" => $item_discount_rate,
          "item_discount_amount" => $item_discount_amount,
          "item_amount_without_vat" => $item_amount_without_vat,
          "item_vat_rate" => $item_vat_rate,
          "item_vat_amount" => $item_vat_amount,
          "item_total_amount" => $item_total_amount,
          "item_type" => $item_type,
        ];
      }

      $amount = (float) ($ndhd["TToan"]["TgTCThue"] ?? 0);
      $discount_amount = (float) ($ndhd["TToan"]["TTCKTMai"] ?? 0);
      $vat_amount = (float) ($ndhd["TToan"]["TgTThue"] ?? 0);
      $total_amount = (float) ($ndhd["TToan"]["TgTTTBSo"] ?? 0);

      $customer = $ndhd["NMua"];
      $company_name = $ndhd["NBan"]["Ten"] ?? NULL;

      $alias_name = $this->invoiceService->findCompanies($company_name);
      if (!isset($alias_name[$company_name])) {
        $this->messenger()->addError($this->t("Not found company"));
        continue;
      }

      $this->entityTypeManager()
        ->getStorage("invoice")
        ->create([
          "label" => $ttchung["THDon"],
          "bundle" => "output_invoices",
          "field_invoice_status" => 1,
          "field_invoice_issue" => 1,
          "field_invoice_is_xml" => 1,
          "field_invoice_date" => $ttchung["NLap"],
          "field_invoice_buyer_legal" => $customer["Ten"],
          "field_invoice_buyer_name" => $customer["Ten"],
          "field_invoice_buyer_phone" => $customer["SDThoai"],
          "field_invoice_buyer_taxcode" => $customer["MST"],
          "field_invoice_buyer_address" => $customer["DChi"],
          "field_invoice_amount" => $amount,
          "field_invoice_discount_amount" => $discount_amount,
          "field_invoice_amount_without_vat" => $amount - $discount_amount,
          "field_invoice_vat_amount" => $vat_amount,
          "field_invoice_total_amount" => $total_amount,
          "field_invoice_items" => $items,
          "field_invoice_id" => $invoice_id,
          "field_invoice_no" => $ttchung["SHDon"],
          "field_invoice_pattern" => $ttchung["KHMSHDon"],
          "field_invoice_serial" => $ttchung["KHMSHDon"] . $ttchung["KHHDon"],
          "field_invoice_payment" => "inv_tm_ck",
          "field_invoice_mccqt" => $data["MCCQT"] ?? NULL,
          "field_invoice_status_cqt" => !empty($data["MCCQT"]) ? 1 : 0,
          "field_invoice_company" => $alias_name[$company_name],
          "field_invoice_origin" => NULL,
        ]
      )
      ->save();
    }

    $this->messenger()->addStatus($this->t("Import invoice successfully"));
    return new RedirectResponse($redirect);
  }

  /**
   * Xem trước hóa đơn.
   */
  public function previewInvoice(Request $request) {
    $data = $request->request->all();
    $array_uuid = explode(',', $data["invoice-uuid"]);

    $custom = $this->invoiceService->getCustom($data["destination"], $array_uuid, "out");
    if ($custom instanceof Response) {
      return $custom;
    }

    $dataConfig = $custom["config"];
    $dataConfig["invoice_template"] = $this->getTemplate($data);

    /** @var \Drupal\e_invoice\InvoiceInterface $dataInvoice */
    $dataInvoice = reset($custom["invoice"]);

    $invoice = $this->handleInvoice->previewInvoice(
      $dataConfig,
      [$dataInvoice->uuid() => $this->getData($dataInvoice)]
    );

    if (!$invoice["success"]) {
      $this->messenger()->addError($invoice["message"]);
      return new RedirectResponse($custom["redirect"]);
    }

    return new TrustedRedirectResponse($invoice["data"]);
  }

  /**
   * Phát hành hóa đơn.
   */
  public function issueInvoice(Request $request) {
    $data = $request->request->all();
    $array_uuid = explode(',', $data["invoice-uuid"]);

    $custom = $this->invoiceService->getCustom($data["destination"], $array_uuid, "out");
    if ($custom instanceof Response) {
      return $custom;
    }

    $dataInv = [];
    $origins = [];
    $get_file = ($data["invoice-get-file"] ?? "") == "TRUE";
    $dataConfig = $custom["config"];
    $dataConfig["invoice_template"] = $this->getTemplate($data);

    /** @var \Drupal\e_invoice\Entity\Invoice $dataInvoice */
    foreach ($custom["invoice"] as $dataInvoice) {
      // Đánh khoá theo uuid để ghép được kết quả trả về (RefID) với hóa đơn.
      $dataInv[$dataInvoice->uuid()] = $this->getData($dataInvoice);

      $origin_entity = $dataInvoice->hasField("field_invoice_origin")
        ? $dataInvoice->get("field_invoice_origin")->entity
        : NULL;

      if ($origin_entity?->hasField("field_invoice_issue")
        && $origin_entity->get("field_invoice_issue")->value === "1") {
        $this->messenger()->addError($this->t("Invoice issued"));
        return new RedirectResponse($custom["redirect"]);
      }

      // Giữ theo uuid để sau khi phát hành còn biết hóa đơn nào ứng với đơn
      // hàng nào — phát hành hàng loạt có thể thành công một phần.
      if ($origin_entity) {
        $origins[$dataInvoice->uuid()] = $origin_entity;
      }
    }

    $invoice = $this->handleInvoice->issueInvoice($custom["invoice"], $dataConfig, $dataInv, $get_file);

    // Báo riêng những hóa đơn nhà cung cấp từ chối, thay vì im lặng bỏ qua.
    foreach ($invoice["errors"] ?? [] as $error) {
      $this->messenger()->addError($error);
    }

    if (!$invoice["success"]) {
      if (empty($invoice["errors"])) {
        $this->messenger()->addError($invoice["message"] ?? $this->t("Issue invoice failed"));
      }

      return new RedirectResponse($custom["redirect"]);
    }

    /** @var \Drupal\e_invoice\InvoiceInterface $issued */
    foreach ($invoice["issued"] ?? [] as $issued) {
      $origin_entity = $origins[$issued->uuid()] ?? NULL;

      if ($origin_entity?->hasField("field_invoice_issue")) {
        $origin_entity->set("field_invoice_issue", 1);
        $origin_entity->save();
      }

      $this->createExportDocument($issued);
    }

    $this->messenger()->addStatus($this->formatPlural(
      count($invoice["issued"] ?? []),
      "Issued invoice successfully",
      "Issued @count invoices successfully"
    ));

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * Thay thế hóa đơn.
   */
  public function replateInvoice(Request $request) {
    $data = $request->request->all();
    $array_uuid = explode(',', $data["invoice-uuid"]);

    $custom = $this->invoiceService->getCustom($data["destination"], $array_uuid, "out");
    if ($custom instanceof Response) {
      return $custom;
    }

    $dataConfig = $custom["config"];
    $dataConfig["invoice_template"] = $this->getTemplate($data);

    /** @var \Drupal\e_invoice\InvoiceInterface $dataInvoice */
    $dataInvoice = reset($custom["invoice"]);
    $invoices = [$dataInvoice->uuid() => $dataInvoice];

    $replate = $this->handleInvoice->replaceInvoice(
      $invoices,
      $dataConfig,
      [$dataInvoice->uuid() => $this->getData($dataInvoice)]
    );

    foreach ($replate["errors"] ?? [] as $error) {
      $this->messenger()->addError($error);
    }

    if (!$replate["success"]) {
      if (!empty($replate["message"])) {
        $this->messenger()->addError($replate["message"]);
      }
    }
    else {
      $this->messenger()->addStatus($this->t("Modified invoice successfully"));
    }

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * Trạng thái hóa đơn.
   */
  public function statusInvoice(Request $request) {
    $data = $request->request->all();
    $array_uuid = explode(',', $data["invoice-uuid"]);

    $custom = $this->invoiceService->getCustom($data["destination"], $array_uuid, "out");
    if ($custom instanceof Response) {
      return $custom;
    }

    $params = [
      "calcu" => $data["calcu"] ?? "false",
      "withCode" => $data["withCode"] ?? "true",
    ];

    $status = $this->handleInvoice->statusInvoice($custom["invoice"], $custom["config"], $params);

    if (!$status["success"]) {
      $this->messenger()->addError($status["message"]);
    }
    else {
      $this->messenger()->addStatus($this->t("Update status invoice successfully"));
    }

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * Pdf hóa đơn đầu ra.
   */
  public function pdfOutputInvoice(Request $request, string $uuid) {
    $destination = $request->query->get("destination");
    $array_uuid = explode(',', $uuid);

    $custom = $this->invoiceService->getCustom($destination, $array_uuid, "out");
    if ($custom instanceof Response) {
      return $custom;
    }

    $file = $this->handleInvoice->pdfOutputInvoice($custom["invoice"], $custom["config"]);

    if (!$file["success"]) {
      $this->messenger()->addError($file["message"]);
    }
    else {
      $this->messenger()->addStatus($this->t("Get file invoice successfully"));

      if (!empty($file["message"])) {
        $this->messenger()->addWarning($file["message"]);
      }
    }

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * Lấy mẫu hóa đơn qua ajax.
   */
  public function getTemplates(string $company_id) {
    /** @var \Drupal\taxonomy\Entity\Term $company_entity */
    $company_entity = $this->entityTypeManager()
      ->getStorage("taxonomy_term")
      ->load($company_id);

    if (empty($company_entity)) {
      return new JsonResponse([
        "success" => FALSE,
        "data" => [],
        "message" => $this->t("Not found company"),
      ]);
    }

    $config_entity = $company_entity->hasField("field_config_invoice")
      ? $company_entity->get("field_config_invoice")->entity
      : NULL;

    if (empty($config_entity)) {
      return new JsonResponse([
        "success" => FALSE,
        "data" => [],
        "message" => $this->t("Not found config"),
      ]);
    }

    $dataConfig = $this->invoiceService->getConfig($config_entity);

    return new JsonResponse([
      "success" => TRUE,
      "data" => $dataConfig["invoice_templates"] ?? [],
    ]);
  }

  /**
   * Lấy thông tin vật tư.
   */
  public function searchItems(Request $request) {
    $data = [
      "id" => "",
      "code" => "",
      "name" => "",
      "unit" => "",
      "tax" => 0,
    ];

    /** @var \Drupal\eck\Entity\EckEntity $node_supplies */
    $node_supplies = $this->entityTypeManager()
      ->getStorage("supplies")
      ->load($request->request->get("target_id"));

    if ($node_supplies) {
      $code = $node_supplies->hasField("field_sup_code") ? $node_supplies->get("field_sup_code")->value : "";
      $unit_ertity = $node_supplies->get("field_sup_unit_buy")->entity;
      $unit = $unit_ertity ? $unit_ertity->label() : "";
      $tax_entity = $node_supplies->get("field_sup_tax")->entity;
      $tax = $tax_entity?->hasField("field_tax_number") ? $tax_entity->get("field_tax_number")->value : 0;

      $data = [
        "id" => $node_supplies->id(),
        "name" => $node_supplies->label(),
        "code" => $code,
        "unit" => $unit,
        "tax" => $tax,
        "type" => 1
      ];
    }

    $response = new JsonResponse([
      "success" => TRUE,
      "data" => $data,
    ], 200);

    return $response;
  }

  /**
   * Đọc mẫu số / ký hiệu hóa đơn người dùng chọn trên modal phát hành.
   *
   * @param array $data
   *   Dữ liệu POST của form.
   *
   * @return array
   *   Mẫu hóa đơn gồm "name", "pattern", "serial".
   */
  private function getTemplate(array $data): array {
    $template = json_decode($data["template-value"] ?? "", TRUE);

    if (!is_array($template) || empty($template["serial"])) {
      throw new BadRequestHttpException("Chưa chọn mẫu số, ký hiệu hóa đơn");
    }

    return $template;
  }

  /**
   * Lấy dữ liệu hóa đơn.
   *
   * @param \Drupal\e_invoice\InvoiceInterface $invoice
   *   Hóa đơn đầu ra.
   *
   * @return array
   *   Dữ liệu chuẩn hoá để gửi cho nhà cung cấp.
   */
  private function getData(InvoiceInterface $invoice): array {
    $products = [];
    $invoice_date = $this->fieldValue($invoice, "field_invoice_date");

    $payment = match ($this->fieldValue($invoice, "field_invoice_payment")) {
      "inv_tm" => "TM",
      "inv_ck" => "CK",
      default => "TM/CK",
    };

    foreach ($invoice->get("field_invoice_items")->getValue() as $item) {
      $quantity = (float) ($item["item_quantity"] ?? 0);
      $price = (float) ($item["item_price"] ?? 0);
      $discount = (float) ($item["item_discount_amount"] ?? 0);
      $vat_amount = (float) ($item["item_vat_amount"] ?? 0);
      $amount = (float) ($item["item_amount"] ?? ($quantity * $price));
      $amount_without_vat = (float) ($item["item_amount_without_vat"] ?? ($amount - $discount));
      $total_amount = (float) ($item["item_total_amount"] ?? ($amount_without_vat + $vat_amount));

      $products[] = [
        "code" => $item["item_code"] ?? NULL,
        "name" => $item["item_name"] ?? NULL,
        "unit" => $item["item_unit"] ?? NULL,
        "quantity" => $quantity,
        "price" => $price,
        "amount" => $amount,
        // Tỷ lệ chiết khấu lưu dạng chuỗi ("5" hoặc "5%"), bỏ ký tự % rồi ép số.
        "discount_rate" => (float) str_replace("%", "", (string) ($item["item_discount_rate"] ?? 0)),
        "discount_amount" => $discount,
        "amount_without_vat" => $amount_without_vat,
        // Không ép kiểu: nhà cung cấp còn nhận tên thuế suất đặc biệt dạng
        // chuỗi ("KCT", "KKKNT") bên cạnh thuế suất số.
        "vat_rate" => is_numeric($item["item_vat_rate"] ?? NULL)
          ? (float) $item["item_vat_rate"]
          : ($item["item_vat_rate"] ?? 0),
        "vat_amount" => $vat_amount,
        "total_amount" => $total_amount,
        "type" => $item["item_type"] ?? 1,
      ];
    }

    return [
      "invoice_uuid" => $invoice->uuid(),
      "invoice_title" => $invoice->label(),
      "invoice_buyer_legal" => $this->fieldValue($invoice, "field_invoice_buyer_legal"),
      "invoice_buyer_name" => $this->fieldValue($invoice, "field_invoice_buyer_name"),
      "invoice_buyer_taxcode" => $this->fieldValue($invoice, "field_invoice_buyer_taxcode"),
      "invoice_buyer_phone" => $this->fieldValue($invoice, "field_invoice_buyer_phone"),
      "invoice_buyer_address" => $this->fieldValue($invoice, "field_invoice_buyer_address"),
      // Tiền hàng trước chiết khấu; trước đây lấy nhầm số đã trừ chiết khấu nên
      // MISA nhận sai TotalSaleAmount.
      "invoice_amount" => (float) $this->fieldValue($invoice, "field_invoice_amount"),
      "invoice_discount_amount" => (float) $this->fieldValue($invoice, "field_invoice_discount_amount"),
      "invoice_amount_without_vat" => (float) $this->fieldValue($invoice, "field_invoice_amount_without_vat"),
      "invoice_vat_amount" => (float) $this->fieldValue($invoice, "field_invoice_vat_amount"),
      "invoice_total_amount" => (float) $this->fieldValue($invoice, "field_invoice_total_amount"),
      // MISA nhận ngày lập theo Y-m-d.
      "invoice_date" => $invoice_date !== "" ? substr($invoice_date, 0, 10) : date("Y-m-d"),
      "invoice_payment" => $payment,
      "products" => $products,
    ];
  }

  /**
   * Đọc giá trị field, trả chuỗi rỗng khi bundle không có field.
   *
   * @param \Drupal\e_invoice\InvoiceInterface $invoice
   *   Hóa đơn cần đọc.
   * @param string $field
   *   Tên field.
   *
   * @return string
   *   Giá trị field.
   */
  private function fieldValue(InvoiceInterface $invoice, string $field): string {
    return $invoice->hasField($field)
      ? (string) $invoice->get($field)->value
      : "";
  }

  /**
   * Lấy dữ liệu file XML.
   */
  private function parseXML(string $file_path) {
    if (!file_exists($file_path)) {
      return [];
    }

    libxml_use_internal_errors(TRUE);
    $xml = simplexml_load_file($file_path, "SimpleXMLElement", LIBXML_NOCDATA);

    if ($xml === FALSE) {
      return [];
    }

    return json_decode(json_encode($xml), TRUE);
  }

  /**
   * Tạo phiếu xuất hàng.
   */
  private function createExportDocument(InvoiceInterface $dataEntity) {
    $list_units = $this->invoiceService->loadTerm();
    $warehouse_id = $this->invoiceService->findWarehouse();

    $export = TRUE;
    $list_items = $dataEntity->get("field_invoice_items")->getValue();

    $itemNames = array_column($list_items, "item_name");
    $dataSupplies = $this->invoiceService->findSupplies($itemNames);

    $buyer_name = $dataEntity->get("field_invoice_buyer_name")->value;
    $supplier_id = $this->invoiceService->findSupplier($buyer_name);

    foreach ($list_items as $k => $item) {
      if (isset($dataSupplies[$item["item_name"]])) {
        $list_items[$k]["item_exist"] = 1;
        $list_items[$k]["item_id"] = $dataSupplies[$item["item_name"]];
      }
      else {
        $list_items[$k]["item_exist"] = 0;
        $export = FALSE;
      }
    }

    $dataEntity->set("field_invoice_items", $list_items);
    $dataEntity->save();

    if ($export && !empty($supplier_id)) {
      try {
        $this->invoiceService->createExport($dataEntity, $supplier_id, $warehouse_id, $list_units);
      }
      catch (\Exception $e) {
        $this->getLogger("erp_e_invoice")->error($e->getMessage());
        $this->messenger()->addError($this->t("Invoice entry failed."));
      }
    }
  }

}

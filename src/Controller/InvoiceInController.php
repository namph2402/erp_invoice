<?php

namespace Drupal\erp_e_invoice\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\e_invoice\Service\HandleInvoice;
use Drupal\erp_e_invoice\InvoiceService;
use Drupal\taxonomy\TermInterface;

/**
 * Returns responses for ERPCons accountant invoice routes.
 */
class InvoiceInController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected HandleInvoice $handleInvoice,
    protected InvoiceService $invoiceService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get("entity_field.manager"),
      $container->get("file_url_generator"),
      $container->get("e_invoice.handle_invoice"),
      $container->get("erp_e_invoice.invoice_service"),
    );
  }

  /**
   * Hóa đơn đầu vào.
   */
  public function listInvoiceIn(Request $request) {
    $data = [];

    $data_invoices = $this->invoiceService->getInvoice($request, "input_invoices");

    $list_status = $this->invoiceService->allowedValueField("input_invoices", "field_invoice_status");
    $list_status_custorm = $this->invoiceService->allowedValueField("input_invoices", "field_invoice_status_custorm");
    $list_status_payment = $this->invoiceService->allowedValueField("input_invoices", "field_amount_payment_status");

    /** @var \Drupal\e_invoice\Entity\Invoice $invoice */
    foreach ($data_invoices["invoices"] as $invoice) {

      /** @var \Drupal\file\Entity\File $invoice_pdf */
      if ($invoice_pdf = $invoice->get("field_invoice_pdf")->entity) {
        $invoice_pdf = $this->fileUrlGenerator->generateAbsoluteString($invoice_pdf->getFileUri());
      }

      /** @var \Drupal\file\Entity\File $invoice_xml */
      if ($invoice_xml = $invoice->get("field_invoice_xml")->entity) {
        $invoice_xml = $this->fileUrlGenerator->generateAbsoluteString($invoice_xml->getFileUri());
      }

      $data[] = [
        "uuid" => $invoice->uuid(),
        "invoice_id" => $invoice->id(),
        "invoice_no" => $invoice->get("field_invoice_no")->value,
        "invoice_date" => $invoice->get("field_invoice_date")->value,
        "invoice_mccqt" => $invoice->get("field_invoice_mccqt")->value,
        "invoice_seller_name" => $invoice->get("field_invoice_seller_name")->value,
        "invoice_seller_taxcode" => $invoice->get("field_invoice_seller_taxcode")->value,
        "invoice_seller_address" => $invoice->get("field_invoice_seller_address")->value,
        "invoice_pattern" => $invoice->get("field_invoice_pattern")->value,
        "invoice_serial" => $invoice->get("field_invoice_serial")->value,
        "invoice_amount_without_vat" => $invoice->get("field_invoice_amount_without_vat")->value ?? 0,
        "invoice_vat_amount" => $invoice->get("field_invoice_vat_amount")->value ?? 0,
        "invoice_total_amount" => $invoice->get("field_invoice_total_amount")->value ?? 0,
        "invoice_payment_due_date" => $invoice->get("field_invoice_payment_due_date")->value,
        "invoice_total_amount_not_payment" => $invoice->get("field_total_amount_not_payment")->value,
        "invoice_total_amount_payment" => $invoice->get("field_total_amount_payment")->value,
        "invoice_buyer_name" => $invoice->get("field_invoice_buyer_name")->value,
        "invoice_buyer_taxcode" => $invoice->get("field_invoice_buyer_taxcode")->value,
        "invoice_buyer_address" => $invoice->get("field_invoice_buyer_address")->value,
        "invoice_refno"  => $invoice->get("field_invoice_refno")->value,
        "invoice_accountant" => $invoice->get("field_invoice_accountant")->value,
        "invoice_accountant_date" => $invoice->get("field_invoice_accounting_date")->value,
        "invoice_license_plate" => $invoice->get("field_invoice_license_plate")->value,
        "invoice_import" => $invoice->get("field_invoice_import")->value ?? 0,
        "invoice_pdf" => $invoice_pdf,
        "invoice_xml" => $invoice_xml,
        "invoice_status" => $invoice->hasField("field_invoice_status")
          ? ($list_status[$invoice->get("field_invoice_status")->value] ?? NULL)
          : NULL,
        "invoice_payment_status" => $invoice->hasField("field_amount_payment_status")
          ? ($list_status_payment[$invoice->get("field_amount_payment_status")->value] ?? NULL)
          : NULL,
        "invoice_payment_status_value" => $invoice->get("field_amount_payment_status")->value,
        "invoice_status_custorm" => $invoice->hasField("field_invoice_status_custorm")
          ? ($list_status_custorm[$invoice->get("field_invoice_status_custorm")->value] ?? NULL)
          : NULL,
      ];
    }

    return [
      "#theme" => "list_input_invoice",
      "#data" => $data,
      "#filter" => [
        "count_import" => $data_invoices["count_import"],
        "date" => [
          "start" => $data_invoices["date"]["start"]
            ? date("Y-m-d", strtotime($data_invoices["date"]["start"]))
            : NULL,
          "end" => $data_invoices["date"]["end"]
            ? date("Y-m-d", strtotime($data_invoices["date"]["end"]))
            : NULL,
        ],
        "company_id" => $data_invoices["company_id"],
        "sell_name" => $data_invoices["sell_name"],
        "option_company" => $data_invoices["option_company"],
        "page_size" => $data_invoices["page_size"],
        "option_page_size" => $data_invoices["option_page_size"],
        "option_payment_status" => $list_status_payment,
        "summary" => $data_invoices["summary"],
        "destination" => $request->getRequestUri(),
        "current_user" => $this->currentUser()->getAccountName(),
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
   * Kéo hóa đơn đầu vào.
   */
  public function modifiedInvoice(Request $request) {
    $data = $request->request->all();

    $redirect = $this->invoiceService->safeRedirect(
      $data["destination"] ?? NULL,
      "erp_e_invoice.e_invoice_list_in"
    );

    $company_id = $data["company_id"] ?? NULL;

    /** @var \Drupal\taxonomy\Entity\Term $company_entity */
    $company_entity = $company_id
      ? $this->entityTypeManager()->getStorage("taxonomy_term")->load($company_id)
      : NULL;

    if (empty($company_entity)) {
      $this->messenger()->addError($this->t("Not found company"));
      return new RedirectResponse($redirect);
    }

    $config_entity = $company_entity->hasField("field_config_invoice")
      ? $company_entity->get("field_config_invoice")->entity
      : NULL;

    if (!$config_entity instanceof TermInterface) {
      $this->messenger()->addError($this->t("Not found config"));
      return new RedirectResponse($redirect);
    }

    $dataConfig = $this->invoiceService->getConfig($config_entity);

    $field["field_invoice_company"] = $company_id;

    $params = [
      "from" => !empty($data["start_date"]) ? date("Y-m-d", strtotime($data["start_date"])) : "",
      "to" => !empty($data["end_date"]) ? date("Y-m-d", strtotime($data["end_date"])) : "",
      "skip" => (int) ($data["skip"] ?? 0),
    ];

    $invoice = $this->handleInvoice->modifiedInvoice($dataConfig, $params, $field);

    if (!$invoice["success"]) {
      $this->messenger()->addError($invoice["message"]);
    }
    else {
      $this->messenger()->addStatus($this->t("Modified invoice successfully"));
    }

    if (!empty($invoice["data"])) {
      $this->createImportDocument($invoice["data"]);
    }

    return new RedirectResponse($redirect);
  }

  /**
   * Hạch toán hóa đơn đầu vào.
   */
  public function accountingInvoice(Request $request) {
    $data = $request->request->all();
    $array_uuid = explode(',', $data["invoice-uuid"]);

    $custom = $this->invoiceService->getCustom($data["destination"], $array_uuid, "in");
    if ($custom instanceof Response) {
      return $custom;
    }

    $param = [
      "accountant" => $data["accoutant"] ?? "",
      "accountant_date" => !empty($data["accoutant-date"])
        ? date("Y-m-d", strtotime($data["accoutant-date"]))
        : date("Y-m-d"),
      "ref_no" => $data["accoutant-refno"],
    ];

    $invoice = $this->handleInvoice->accountingInvoice($custom["invoice"], $custom["config"], $param);

    if (!$invoice["success"]) {
      $this->messenger()->addError($invoice["message"]);
    }
    else {
      $this->messenger()->addStatus($this->t("Accoutant invoice successfully"));
    }

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * Cập nhật thông tin thanh toán hóa đơn đầu vào.
   */
  public function paymentInvoice(Request $request) {
    $data = $request->request->all();
    $array_uuid = explode(',', $data["invoice-uuid"] ?? "");

    $custom = $this->invoiceService->getCustom($data["destination"] ?? NULL, $array_uuid, "in");
    if ($custom instanceof Response) {
      return $custom;
    }

    $param = [
      "payment_date" => !empty($data["payment-date"])
        ? date("Y-m-d", strtotime($data["payment-date"]))
        : NULL,
      "payment_pair" => $data["payment-pair"] ?? $this->currentUser()->getAccountName(),
      "total_amount_payment" => isset($data["payment-amount"]) && $data["payment-amount"] !== ""
        ? (float) $data["payment-amount"]
        : 0,
      "amount_payment" => isset($data["payment-status"]) && $data["payment-status"] !== ""
        ? (int) $data["payment-status"]
        : 0,
    ];

    if (isset($data["payment-amount-not"]) && $data["payment-amount-not"] !== "") {
      $param["total_amount_not_payment"] = (float) $data["payment-amount-not"];
    }

    $invoice = $this->handleInvoice->paymentInvoice($custom["invoice"], $custom["config"], $param);

    if (!$invoice["success"]) {
      $this->messenger()->addError($invoice["message"]);
    }
    else {
      $this->messenger()->addStatus($this->t("Update payment invoice successfully"));
    }

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * Hủy hạch toán hóa đơn đầu vào.
   */
  public function cancelAccountingInvoice(Request $request, string $uuid) {
    $data = $request->query->all();
    $array_uuid = explode(',', $uuid);

    $custom = $this->invoiceService->getCustom($data["destination"], $array_uuid, "in");
    if ($custom instanceof Response) {
      return $custom;
    }

    $invoice = $this->handleInvoice->accountingInvoice($custom["invoice"], $custom["config"]);

    if (!$invoice["success"]) {
      $this->messenger()->addError($invoice["message"]);
    }
    else {
      $this->messenger()->addStatus($this->t("Cancel accounting invoice successfully"));
    }

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * File hóa đơn đầu vào.
   */
  public function downloadFileInputInvoice(Request $request, string $type, string $uuid) {
    return $this->getFileInputInvoice(
      $type,
      explode(',', $uuid),
      $request->query->get("destination")
    );
  }

  /**
   * File của nhiều hóa đơn đầu vào cùng lúc.
   */
  public function downloadFilesInputInvoice(Request $request) {
    $data = $request->request->all();

    $destination = $data["destination"] ?? NULL;

    $array_uuid = array_filter(array_map(
      "trim",
      explode(',', $data["invoice-uuid"] ?? "")
    ));

    if (empty($array_uuid)) {
      $this->messenger()->addError($this->t("Please select at least one invoice."));

      return new RedirectResponse($this->invoiceService->safeRedirect(
        $destination,
        "erp_e_invoice.e_invoice_list_in"
      ));
    }

    return $this->getFileInputInvoice($data["file-type"] ?? "", $array_uuid, $destination);
  }

  /**
   * Kéo file hóa đơn đầu vào về theo loại file.
   *
   * @param string $type
   *   Loại file cần lấy: pdf, xml hoặc all.
   * @param array $array_uuid
   *   Danh sách uuid hóa đơn.
   * @param string|null $destination
   *   Đường dẫn quay lại sau khi lấy file.
   */
  private function getFileInputInvoice(string $type, array $array_uuid, ?string $destination) {
    $list_type = [
      "all" => 1,
      "pdf" => 2,
      "xml" => 3
    ];

    if (!isset($list_type[$type])) {
      $this->messenger()->addError($this->t("Not found type"));

      return new RedirectResponse($this->invoiceService->safeRedirect(
        $destination,
        "erp_e_invoice.e_invoice_list_in"
      ));
    }

    $custom = $this->invoiceService->getCustom($destination, $array_uuid, "in");
    if ($custom instanceof Response) {
      return $custom;
    }

    $file = $this->handleInvoice->fileInputInvoice($custom["invoice"], $custom["config"], $list_type[$type]);

    if (!$file["success"]) {
      $this->messenger()->addError($file["message"]);
    }
    else {
      $this->messenger()->addStatus($this->t("Get file invoice successfully"));
    }

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * Tạo phiếu nhập hàng cho các hóa đơn vừa kéo về.
   *
   * @param array $dataEntity
   *   Danh sách hóa đơn đầu vào mới lấy được.
   */
  private function createImportDocument(array $dataEntity) {
    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    foreach ($dataEntity as $invoice) {
      $this->invoiceService->autoCreateDocument($invoice, "import");
    }
  }

}

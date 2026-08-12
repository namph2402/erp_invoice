<?php

namespace Drupal\erp_e_invoice\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\e_invoice\Service\GetConfigInvoice;
use Drupal\e_invoice\Service\HandleInvoice;
use Drupal\erp_e_invoice\InvoiceService;

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
    protected UuidInterface $uuid,
    protected GetConfigInvoice $config,
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
      $container->get("uuid"),
      $container->get("e_invoice.get_config"),
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
    $list_status_custorm = $this->invoiceService->allowedValueField("input_invoices", "field_invoice_status_custorm");

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
        "invoice_name" => $invoice->label(),
        "invoice_no" => $invoice->get("field_invoice_no")->value,
        "invoice_date" => $invoice->get("field_invoice_date")->value,
        "invoice_status_custorm" => $list_status_custorm[$invoice->get("field_invoice_status_custorm")->value] ?? NULL,
        "invoice_pattern" =>$invoice->get("field_invoice_pattern")->value,
        "invoice_serial" =>$invoice->get("field_invoice_serial")->value,
        "invoice_seller_name" => $invoice->get("field_invoice_seller_name")->value ?? "",
        "invoice_seller_taxcode" => $invoice->get("field_invoice_seller_taxcode")->value ?? "",
        "invoice_amount_without_vat" => $invoice->get("field_invoice_amount_without_vat")->value ?? 0,
        "invoice_vat_amount" => $invoice->get("field_invoice_vat_amount")->value ?? 0,
        "invoice_total_amount" => $invoice->get("field_invoice_total_amount")->value ?? 0,
        "invoice_accountant" => $invoice->get("field_invoice_accountant")->value ?? "",
        "invoice_accountant_date" => $invoice->get("field_invoice_accounting_date")->value ?? "",
        "invoice_import" => $invoice->get("field_invoice_import")->value ?? 0,
        "invoice_pdf" => $invoice_pdf,
        "invoice_xml" => $invoice_xml,
      ];
    }

    return [
      "#theme" => "list_input_invoice",
      "#data" => $data,
      "#filter" => [
        "count_import" => $data_invoices["count_import"],
        "date" => [
          "start" => $data_invoices["date"]["start"] ? date("Y-m-d", strtotime($data_invoices["date"]["start"])) : NULL,
          "end" => $data_invoices["date"]["end"] ? date("Y-m-d", strtotime($data_invoices["date"]["end"])) : NULL,
        ],
        "company_id" => $data_invoices["company_id"],
        "option_company" => $data_invoices["option_company"],
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

    if (empty($config_entity)) {
      $this->messenger()->addError($this->t("Not found config"));
      return new RedirectResponse($redirect);
    }

    $dataConfig = $this->invoiceService->getConfig($config_entity);

    $field["field_invoice_company"] = $company_id;

    $params = [
      "from" => !empty($data["start_date"]) ? date("Y-m-d", strtotime($data["start_date"])) : "",
      "to" => !empty($data["end_date"]) ? date("Y-m-d", strtotime($data["end_date"])) : "",
      "skip" => $data["skip"] ?? "0",
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
      "accountant" => $data["accoutant"],
      "accountant_date" => $data["accoutant-date"],
      "ref_no" => $this->uuid->generate(),
    ];

    /** @var \Drupal\e_invoice\InvoiceInterface $dataInvoice */
    $dataInvoice = reset($custom["invoice"]);

    $invoice = $this->handleInvoice->accountingInvoice($dataInvoice, $custom["config"], $param);

    if (!$invoice["success"]) {
      $this->messenger()->addError($invoice["message"]);
    }
    else {
      $this->messenger()->addStatus($this->t("Accoutant invoice successfully"));
    }

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * Pdf hóa đơn đầu vào.
   */
  public function downloadFileInputInvoice(Request $request, string $type, string $uuid) {
    $destination = $request->query->get("destination");
    $array_uuid = explode(',', $uuid);

    $custom = $this->invoiceService->getCustom($destination, $array_uuid, "in");
    if ($custom instanceof Response) {
      return $custom;
    }

    /** @var \Drupal\e_invoice\InvoiceInterface $dataInvoice */
    $dataInvoice = reset($custom["invoice"]);

    $file = $this->handleInvoice->fileInputInvoice($dataInvoice, $custom["config"]);

    if (!$file["success"]) {
      $this->messenger()->addError($file["message"]);
    }
    else {
      $this->messenger()->addStatus($this->t("Get file invoice successfully"));
    }

    return new RedirectResponse($custom["redirect"]);
  }

  /**
   * Tạo phiếu nhập hàng.
   */
  private function createImportDocument(array $dataEntity) {
    $list_units = $this->invoiceService->loadTerm();
    $warehouse_id = $this->invoiceService->findWarehouse();

    /** @var \Drupal\e_invoice\InvoiceInterface $data */
    foreach ($dataEntity as $data) {
      $import = TRUE;
      $list_items = $data->get("field_invoice_items")->getValue();

      $itemNames = array_column($list_items, "item_name");
      $dataSupplies = $this->invoiceService->findSupplies($itemNames);

      $seller_name = $data->get("field_invoice_seller_name")->value;
      $supplier_id = $this->invoiceService->findSupplier($seller_name);

      foreach ($list_items as $k => $item) {
        if (isset($dataSupplies[$item["item_name"]])) {
          $list_items[$k]["item_exist"] = 1;
          $list_items[$k]["item_id"] = $dataSupplies[$item["item_name"]];
        }
        else {
          $list_items[$k]["item_exist"] = 0;
          $import = FALSE;
        }
      }

      $data->set("field_invoice_items", $list_items);
      $data->save();

      if ($import && !empty($supplier_id)) {
        try {
          $this->invoiceService->createImport($data, $supplier_id, $warehouse_id, $list_units);
        }
        catch (\Exception $e) {
          $this->getLogger("erp_e_invoice")->error($e->getMessage());
          $this->messenger()->addError($this->t("Invoice entry failed."));
        }
      }
    }
  }
}

<?php

namespace Drupal\erp_e_invoice\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\e_invoice\Service\GetConfigInvoice;
use Drupal\e_invoice\Service\HandleInvoice;
use Drupal\erp_e_invoice\InvoiceService;

/**
 * Returns responses for ERPCons accountant out invoice routes.
 */
class InvoiceWarehouseController extends ControllerBase {

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
   * Phiếu nhập hàng.
   */
  public function listInvoiceImport(Request $request) {
    $data = $this->getData($request, "import_warehouse");

    return [
      "#theme" => "list_import_invoice",
      "#data" => $data["items"],
      "#filter" => [
        "date" => [
          "start" => $data["start_date"] ? date("Y-m-d", strtotime($data["start_date"])) : NULL,
          "end" => $data["end_date"] ? date("Y-m-d", strtotime($data["end_date"])) : NULL,
        ],
        "company_id" => $data["company_id"],
        "option_company" => $data["option_company"],
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
   * Phiếu xuất hàng.
   */
  public function listInvoiceExport(Request $request) {
    $data = $this->getData($request, "warehouse_storage_history");

    return [
      "#theme" => "list_export_invoice",
      "#data" => $data["items"],
      "#filter" => [
        "date" => [
          "start" => $data["start_date"] ? date("Y-m-d", strtotime($data["start_date"])) : NULL,
          "end" => $data["end_date"] ? date("Y-m-d", strtotime($data["end_date"])) : NULL,
        ],
        "company_id" => $data["company_id"],
        "option_company" => $data["option_company"],
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
   * Phiếu xuất hàng.
   */
  public function listWarehouse() {
    $data = [];
    $suppliesManage = $this->entityTypeManager()->getStorage("supplies");
    $warehouse_id = $this->invoiceService->findWarehouse();

    // Danh sách term vật tư + thuế.
    $list_taxs = $this->invoiceService->loadTerm("tax", "id", "field_tax_number");
    $list_units = $this->invoiceService->loadTerm("unit", "id");

    $supplies_ids = $suppliesManage->getQuery()
      ->condition("field_sup_list_warehouse", $warehouse_id)
      ->sort("title", "ASC")
      ->pager(20)
      ->accessCheck(TRUE)
      ->execute();

    $supplies = $suppliesManage->loadMultiple($supplies_ids);

    /** @var \Drupal\eck\Entity\EckEntity $supplie */
    foreach ($supplies as $supplie) {
      $quantity = 0;

      /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $warehouse_field */
      $warehouse_field = $supplie->get('field_sup_warehouse');
      $list_warehouse = $warehouse_field->referencedEntities();

      /** @var \Drupal\paragraphs\Entity\Paragraph $warehouse */
      foreach ($list_warehouse as $warehouse) {
        if ($warehouse->get("field_warehouse")->target_id == $warehouse_id) {
          $quantity = $warehouse->get("field_warehouse_quantity")->value ?? 0;
          break;
        }
      }

      $unit_id = $supplie->get("field_sup_unit_buy")->target_id;
      $unit = $list_units[$unit_id] ?? "";

      $tax_id = $supplie->get("field_sup_tax")->target_id;
      $tax = $list_taxs[$tax_id] ?? "";

      $data[] = [
        "id" => $supplie->id(),
        "uuid" => $supplie->uuid(),
        "name" => $supplie->label(),
        "code" => $supplie->get("field_sup_code")->value,
        "unit" => $unit,
        "tax" => $tax,
        "quantity" => $quantity
      ];
    }

    return [
      "#theme" => "list_warehouse_invoice",
      "#data" => $data,
      "#filter" => [
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
   * Chi tiết phiếu nhập.
   */
  public function detailInvoiceImport(string|int $id) {
    $data = [];
    /** @var \Drupal\eck\Entity\EckEntity $supplie */
    $supplie = $this->entityTypeManager()->getStorage("supplies")->load($id);

    if (!$supplie || !$supplie->hasField("field_sup_import_history")) {
      throw new NotFoundHttpException();
    }

    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $import_history_field */
    $import_history_field = $supplie->get('field_sup_import_history');
    $list_detail = $import_history_field->referencedEntities();

    foreach ($list_detail as $detail) {
      /** @var \Drupal\paragraphs\Entity\Paragraph $detail */
      if ($detail->hasField("field_document_type") &&
        $detail->get("field_document_type")->value === "e_invoice") {
        $invoice = $detail->get("field_ih_invoice")->entity;
        $import = $detail->get("field_ih_receipt")->entity;

        $data[] = [
          "date" => $detail->get("field_ih_received_date")->value,
          "quantity" => $detail->get("field_ih_quantity")->value,
          "price" => $detail->get("field_ih_purchase_price")->value,
          "invoice_uuid"=> $invoice?->uuid(),
          "invoice_title"=> $invoice?->label(),
          "import_uuid"=> $import?->uuid(),
          "import_title"=> $import?->label(),
        ];
      }
    }

    return [
      "#theme" => "detail_import_invoice",
      "#data" => $data,
      "#cache" => ["max-age" => 0],
    ];
  }

  /**
   * Chi tiết phiếu xuất.
   */
  public function detailInvoiceExport(string|int $id) {
    $data = [];

    /** @var \Drupal\eck\Entity\EckEntity $supplie */
    $supplie = $this->entityTypeManager()->getStorage("supplies")->load($id);

    if (!$supplie || !$supplie->hasField("field_sup_export_history")) {
      throw new NotFoundHttpException();
    }

    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $export_history_field */
    $export_history_field = $supplie->get('field_sup_export_history');
    $list_detail = $export_history_field->referencedEntities();

    foreach ($list_detail as $detail) {
      /** @var \Drupal\paragraphs\Entity\Paragraph $detail */
      if ($detail->hasField("field_document_type") &&
        $detail->get("field_document_type")->value === "e_invoice") {
        $invoice = $detail->get("field_he_invoice")->entity;
        $export = $detail->get("field_he_export")->entity;
        $data[] = [
          "date" => $detail->get("field_he_date_export")->value,
          "quantity" => $detail->get("field_he_output_quantity")->value,
          "price" => $detail->get("field_he_price")->value,
          "invoice_uuid"=> $invoice?->uuid(),
          "invoice_title"=> $invoice?->label(),
          "export_uuid"=> $export?->uuid(),
          "export_title"=> $export?->label(),
        ];
      }
    }

    return [
      "#theme" => "detail_export_invoice",
      "#data" => $data,
      "#cache" => ["max-age" => 0],
    ];
  }

  /**
   * Lấy dữ liệu phiếu.
   */
  private function getData(Request $request, string $type) {
    $nodeManage = $this->entityTypeManager()->getStorage("node");
    $termManage = $this->entityTypeManager()->getStorage("taxonomy_term");

    // Bộ lọc.
    $start_date = !empty($request->query->get("start_date")) ? $request->query->get("start_date") : date("Y-m-01");
    $end_date = !empty($request->query->get("end_date")) ? $request->query->get("end_date") : date("Y-m-d");
    $search_date = $request->query->get("search_date");

    if ($search_date === "last_month") {
      $start_date = date("Y-m-01", strtotime("first day of last month"));
      $end_date = date("Y-m-t", strtotime("last month"));
    }
    elseif ($search_date === "last_week") {
      $start_date = date("Y-m-d", strtotime("monday last week"));
      $end_date = date("Y-m-d", strtotime("sunday last week"));
    }
    elseif ($search_date === "full_date") {
      $start_date = $end_date = NULL;
    }

    /** @var \Drupal\user\Entity\User $current_user */
    $current_user = $this->entityTypeManager()
      ->getStorage("user")
      ->load($this->currentUser()->id());

    // Danh sách công ty được gán.
    $company_user = $current_user?->get("field_user_company")->entity;
    $company_id = $request->query->get("company_id") ?? $company_user?->id();

    // Chưa gán công ty thì không thấy phiếu nào, thay vì thấy toàn bộ.
    $list_company_id = $company_id
      ? $this->invoiceService->getDescendantTids($company_id, $termManage)
      : [0];
    $option_company = $current_user ? $this->invoiceService->getCurrentPosition($current_user) : [];

    if ($company_user) {
      $option_company[$company_user->id()] = $company_user->label();
    }

    $data = [
      "company_id" => $company_id,
      "option_company" => $option_company,
      "start_date" => $start_date,
      "end_date" => $end_date,
      "items" => []
    ];

    $field_type = $type === "import_warehouse" ? "field_iw_type" : "field_wsh_type";
    $field_date = $type === "import_warehouse" ? "field_wsh_received_date" : "field_wsh_date_export";

    $node_query = $nodeManage->getQuery()
      ->condition("type", $type)
      ->condition($field_type, "einvoice")
      ->condition("field_company", $list_company_id, "IN")
      ->sort($field_date, "DESC")
      ->sort("created", "DESC")
      ->pager(20)
      ->accessCheck(TRUE);

    if (!empty($start_date)) {
      $node_query->condition($field_date . ".value", $start_date, ">=");
    }
    if (!empty($end_date)) {
      $node_query->condition($field_date . ".value", $end_date, "<=");
    }

    $node_ids = $node_query->execute();
    $node_invoices = $nodeManage->loadMultiple($node_ids);

    /** @var \Drupal\node\Entity\Node $node */
    foreach ($node_invoices as $node) {
      $invoice = $node->get("field_e_invoice")->entity;

      $data["items"][] = [
        "uuid" => $node->uuid(),
        "title" => $node->label(),
        "date" => $node->get($field_date)->value,
        "company" => $node->get("field_company")->entity?->label() ?? "",
        "supplier" => $node->get("field_wsh_client")->entity?->label() ?? "",
        "total_vat" => $node->get("field_wsh_total_tax")->value,
        "total_amount" => $node->get("field_wsh_total_excl_tax")->value,
        "invoice_uuid" => $invoice?->uuid() ?? "",
        "invoice_title" => $invoice?->label() ?? "",
      ];
    }

    return $data;
  }
}

<?php

namespace Drupal\erp_e_invoice;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\e_invoice\InvoiceInterface;
use Drupal\e_invoice\Service\GetConfigInvoice;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Invoice service.
 */
class InvoiceService {

  use StringTranslationTrait;

  /**
   * Cấu hình view dùng cho autocomplete chọn vật tư.
   */
  private const AUTOCOMPLETE_SELECTION_SETTINGS = [
    "view_name" => "sorl_supplies",
    "display_name" => "entity_reference_sorl_supplies",
  ];

  /**
   * Số dòng cho phép hiển thị trong bảng danh sách hóa đơn.
   *
   * Danh sách không phân trang nữa nên phải chặn số dòng người dùng chọn theo
   * danh sách trắng, tránh việc tải toàn bộ hóa đơn ra màn hình.
   */
  public const ALLOWED_LIMITS = [20, 50, 100, 200, 500, 1000];

  /**
   * Số dòng mặc định khi người dùng chưa chọn.
   */
  public const DEFAULT_LIMIT = 50;

  /**
   * Các trường tiền cần cộng tổng theo bộ lọc.
   */
  private const SUM_FIELDS = [
    "amount_without_vat" => "field_invoice_amount_without_vat",
    "vat_amount" => "field_invoice_vat_amount",
    "total_amount" => "field_invoice_total_amount",
  ];

  /**
   * Constructs an InvoiceService object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param AccountProxyInterface $currentUser
   *   The current user.
   * @param MessengerInterface $messenger
   *   The messenger.
   * @param GetConfigInvoice $config
   *   The e-invoice config service.
   * @param  $connection
   *   The database connection.
   * @param KeyValueFactoryInterface $keyValue
   *   The key/value factory.
   * @param LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected MessengerInterface $messenger,
    protected GetConfigInvoice $config,
    protected Connection $connection,
    protected KeyValueFactoryInterface $keyValue,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Khoá autocomplete chọn vật tư, dùng chung cho form alter và form cập nhật.
   *
   * @return string
   *   Khoá selection_settings đã đăng ký trong key/value store.
   */
  public function getAutocompleteSettingsKey(): string {
    $settings = self::AUTOCOMPLETE_SELECTION_SETTINGS;
    $key = Crypt::hmacBase64(serialize($settings), Settings::getHashSalt());

    $storage = $this->keyValue->get("entity_reference_autocomplete");
    if (!$storage->has($key)) {
      $storage->set($key, $settings);
    }

    return $key;
  }

  /**
   * Chuẩn hoá đường dẫn quay lại do người dùng gửi lên.
   *
   * $destination đến từ input của form nên không được dùng thẳng cho
   * RedirectResponse: URL tuyệt đối hoặc protocol-relative sẽ biến thành lỗ
   * hổng open redirect. Chỉ chấp nhận đường dẫn nội bộ bắt đầu bằng một dấu "/".
   *
   * @param string|null $destination
   *   Giá trị destination lấy từ request.
   * @param string $route
   *   Route dùng làm mặc định khi destination không hợp lệ.
   *
   * @return string
   *   Đường dẫn nội bộ an toàn để redirect.
   */
  public function safeRedirect(?string $destination, string $route): string {
    $destination = trim((string) $destination);

    if ($destination !== ''
      && str_starts_with($destination, '/')
      && !str_starts_with($destination, '//')
      && !UrlHelper::isExternal($destination)
    ) {
      return rtrim($destination, '/') ?: '/';
    }

    return Url::fromRoute($route)->toString();
  }

  /**
   * Tìm hóa đơn theo uuid kèm cấu hình công ty phát hành.
   */
  public function getCustom(?string $destination, array $uuid, string $type) {
    $routing = $type == "out"
      ? "erp_e_invoice.e_invoice_list_out"
      : "erp_e_invoice.e_invoice_list_in";

    $redirect = $this->safeRedirect($destination, $routing);

    $invoices = $this->entityTypeManager
      ->getStorage("invoice")
      ->loadByProperties([
        "uuid" => $uuid,
      ]);

    if (empty($invoices)) {
      $this->messenger->addError($this->t("Not found invoice"));
      return new RedirectResponse($redirect);
    }

    $company_id = NULL;
    $config_company = [];

    $invoices = array_combine(
      array_map(static fn ($invoice) => $invoice->uuid(), $invoices),
      $invoices
    );

    /** @var \Drupal\e_invoice\Entity\Invoice $invoice */
    foreach ($invoices as $invoice) {
      $invoice_company_id = $invoice->hasField("field_invoice_company")
        ? $invoice->get("field_invoice_company")->target_id
        : NULL;

      if ($company_id !== NULL) {
        if ($invoice_company_id == $company_id) {
          continue;
        }

        $this->messenger->addError(
          $this->t("Please select invoices belonging to the same company.")
        );
        return new RedirectResponse($redirect);
      }

      $company_entity = $invoice->hasField("field_invoice_company")
        ? $invoice->get("field_invoice_company")->entity
        : NULL;

      if (empty($company_entity)) {
        $this->messenger->addError($this->t("Not found company"));
        return new RedirectResponse($redirect);
      }

      $company_id = $company_entity->id();

      $config_entity = $company_entity->hasField("field_config_invoice")
        ? $company_entity->get("field_config_invoice")->entity
        : NULL;

      if (!$config_entity instanceof TermInterface) {
        $this->messenger->addError($this->t("Not found config"));
        return new RedirectResponse($redirect);
      }

      $config_company = $this->getConfig($config_entity);
    }

    return [
      "redirect" => $redirect,
      "invoice" => $invoices,
      "config" => $config_company,
    ];
  }

  /**
   * Danh sách hóa đơn điện tử theo loại.
   */
  public function getInvoice(Request $request, string $type) {
    $termManage = $this->entityTypeManager->getStorage("taxonomy_term");
    $invoiceManage = $this->entityTypeManager->getStorage("invoice");

    $count_import = $count_export = 0;

    $vn_tz = new \DateTimeZone('Asia/Ho_Chi_Minh');
    $utc_tz = new \DateTimeZone('UTC');

    $start_date = !empty($request->query->get("start_date"))
      ? $request->query->get("start_date")
      : date("Y-m-01");

    $end_date = !empty($request->query->get("end_date"))
      ? $request->query->get("end_date")
      : date("Y-m-d");

    $import = $request->query->get("import");
    $export = $request->query->get("export");
    $status = $request->query->get("status") ?? "";
    $limit = $this->getLimit($request);

    switch ($request->query->get("search_date")) {
      case 'last_month':
        $start_date = date("Y-m-01", strtotime("first day of last month"));
        $end_date = date("Y-m-t", strtotime("last month"));
        break;
      case 'last_week':
        $start_date = date("Y-m-d", strtotime("monday last week"));
        $end_date = date("Y-m-d", strtotime("sunday last week"));
        break;
      case 'full_date':
        $start_date = $end_date = NULL;
        break;
      default:
        break;
    }

    /** @var \Drupal\user\Entity\User $current_user */
    $current_user = $this->entityTypeManager
      ->getStorage("user")
      ->load($this->currentUser->id());

    $company_user = $current_user?->get("field_user_company")->entity;
    $company_id = $request->query->get("company_id") ?? $company_user?->id();

    $list_company_id = $company_id
      ? $this->getDescendantTids($company_id, $termManage)
      : [0];

    $option_company = $current_user ? $this->getCurrentPosition($current_user) : [];

    if ($company_user) {
      $option_company[$company_user->id()] = $company_user->label();
    }

    $invoices_query = $invoiceManage->getQuery()
      ->condition("bundle", $type)
      ->condition("field_invoice_company", $list_company_id, "IN")
      ->sort("field_invoice_date", "DESC")
      ->sort("field_invoice_status", "ASC")
      ->sort("created", "DESC")
      ->accessCheck(TRUE);

    if ($import == "true") {
      $group = $invoices_query->orConditionGroup()
        ->condition("field_invoice_import", [1, 2], "NOT IN")
        ->notExists("field_invoice_import");
 
      $invoices_query->condition($group);
    }
    elseif ($export == "true") {
      $invoices_query->condition("field_invoice_issue", 1)
        ->condition("field_invoice_status", [1, 2, 3], "IN");

      $group = $invoices_query->orConditionGroup()
        ->condition("field_invoice_export", [1, 2], "NOT IN")
        ->notExists("field_invoice_export");

      $invoices_query->condition($group);
    }
    else {
      if (!empty($start_date)) {
        $created_at_start = (new \DateTime($start_date . ' 00:00:00', $vn_tz))->setTimezone($utc_tz);
        $invoices_query->condition("field_invoice_date.value", $created_at_start->format('Y-m-d\TH:i:s'), ">=");
      }
      if (!empty($end_date)) {
        $created_at_end = (new \DateTime($end_date . ' 23:59:59', $vn_tz))->setTimezone($utc_tz);
        $invoices_query->condition("field_invoice_date.value", $created_at_end->format('Y-m-d\TH:i:s'), "<=");
      }
      if ($status != "") {
        $invoices_query->condition("field_invoice_status", $status);
      }
    }

    // Bảng không phân trang nữa: bản sao không giới hạn dòng dùng để cộng tổng
    // tiền của cả bộ lọc, còn truy vấn chính chỉ lấy đúng số dòng cần hiển thị.
    $summary_query = clone $invoices_query;

    $invoice_ids = $invoices_query->range(0, $limit)->execute();
    $list_invoices = $invoiceManage->loadMultiple($invoice_ids);

    $summary = $this->sumInvoice($summary_query->execute());

    if ($type === "input_invoices") {
      $invoices_query = $invoiceManage->getQuery()
        ->condition("bundle", $type)
        ->condition("field_invoice_company", $list_company_id, "IN")
        ->accessCheck(TRUE);

      $group = $invoices_query->orConditionGroup()
        ->condition("field_invoice_import", [1, 2], "NOT IN")
        ->notExists("field_invoice_import");

      $invoices_query->condition($group);
      $count_import = (int) $invoices_query->count()->execute() ?? 0;
    }
    elseif ($type === "output_invoices") {
      $invoices_query = $invoiceManage->getQuery()
        ->condition("bundle", $type)
        ->condition("field_invoice_company", $list_company_id, "IN")
        ->condition("field_invoice_issue", 1)
        ->condition("field_invoice_status", [1, 2, 3], "IN")
        ->accessCheck(TRUE);

      $group = $invoices_query->orConditionGroup()
        ->condition("field_invoice_export", [1, 2], "NOT IN")
        ->notExists("field_invoice_export");

      $invoices_query->condition($group);
      $count_export = (int) $invoices_query->count()->execute() ?? 0;
    }

    return [
      "company_id" => $company_id,
      "status" => $status,
      "option_company" => $option_company,
      "invoices" => $list_invoices,
      "count_import" => $count_import,
      "count_export" => $count_export,
      "limit" => $limit,
      "option_limit" => self::ALLOWED_LIMITS,
      "summary" => $summary,
      "date" => [
        "start" => $start_date,
        "end" => $end_date,
      ],
    ];
  }

  /**
   * Số dòng hiển thị người dùng chọn trên bảng danh sách.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request hiện tại.
   *
   * @return int
   *   Số dòng nằm trong danh sách trắng.
   */
  public function getLimit(Request $request): int {
    $limit = (int) $request->query->get("limit");

    return in_array($limit, self::ALLOWED_LIMITS, TRUE)
      ? $limit
      : self::DEFAULT_LIMIT;
  }

  /**
   * Cộng tổng tiền của toàn bộ hóa đơn khớp bộ lọc.
   *
   * Cộng thẳng trên bảng field thay vì load entity, vì số hóa đơn khớp bộ lọc
   * thường lớn hơn nhiều số dòng đang hiển thị.
   *
   * @param array $ids
   *   Danh sách id hóa đơn khớp bộ lọc.
   *
   * @return array
   *   Tổng tiền trước thuế, tiền thuế, tổng tiền và số hóa đơn.
   */
  public function sumInvoice(array $ids): array {
    $summary = [
      "count" => count($ids),
      "amount_without_vat" => 0,
      "vat_amount" => 0,
      "total_amount" => 0,
    ];

    if (empty($ids)) {
      return $summary;
    }

    $query = $this->connection->select("invoice", "i");
    $query->condition("i.id", $ids, "IN");

    foreach (self::SUM_FIELDS as $key => $field) {
      $alias = $query->leftJoin(
        "invoice__" . $field,
        "sum_" . $key,
        "%alias.entity_id = i.id AND %alias.deleted = 0 AND %alias.delta = 0"
      );

      $query->addExpression("SUM(" . $alias . "." . $field . "_value)", $key);
    }

    $result = $query->execute()->fetchAssoc() ?: [];

    foreach (array_keys(self::SUM_FIELDS) as $key) {
      $summary[$key] = (float) ($result[$key] ?? 0);
    }

    return $summary;
  }

  /**
   * Lấy các giá trị trường select list.
   */
  public function allowedValueField(string $bundle, string $field): array {
    $field_config = $this->entityTypeManager
      ->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'invoice',
        'bundle' => $bundle,
        'field_name' => $field,
      ]);

    /** @var \Drupal\field\Entity\FieldConfig|false $field_config */
    $field_config = reset($field_config);

    if (!$field_config) {
      return [];
    }

    return $field_config
      ->getFieldStorageDefinition()
      ->getSetting('allowed_values') ?? [];
  }

  /**
   * Lấy cấu hình hóa đơn điện tử công ty qua taxonomy.
   */
  public function getConfig(TermInterface $config_entity): array {
    return $this->config->handle($config_entity);
  }

  /**
   * Tạo tên bí danh.
   */
  public function createAliasName(string|int $id, string $name, $type = "supplies", $field = "field_sup_alias_names") {
    $exist = FALSE;

    $entity = $this->entityTypeManager
      ->getStorage($type)
      ->load($id);

    if (!$entity || !$entity->hasField($field)) {
      return NULL;
    }

    foreach ($entity->get($field)->getValue() as $alias) {
      if ($alias["value"] === $name) {
        $exist = TRUE;
        break;
      }
    }

    if (!$exist) {
      $entity->get($field)->appendItem([
        "value" => $name,
      ]);

      $entity->save();
    }

    return $entity->id();
  }

  /**
   * Tạo vật tư.
   */
  public function createSupplies(string $code, string $name, string|int|null $unit, string $type, string|int $company) {
    $supply = $this->entityTypeManager
      ->getStorage("supplies")
      ->create([
        "title" => $name,
        "type" => "supplies",
        "field_sup_code" => $code,
        "field_sup_unit_buy" => $unit ?? NULL,
        "field_sup_category" => $type,
        "field_sup_company" => [
          ["target_id" => $company],
        ],
      ]);

    $supply->save();
    return $supply->id();
  }

  /**
   * Tạo nhà cung cấp.
   */
  public function createSupplier(string $name,  string|int $taxcode, string|int $company) {
    $supplier = $this->entityTypeManager
      ->getStorage("crm")
      ->create([
        "title" => $name,
        "type" => "customers",
        "field_taxcode" => $taxcode,
        "field_sup_company" => [
          ["target_id" => $company],
        ],
      ]);

    $supplier->save();
    return $supplier->id();
  }

  /**
   * Tìm tên vật tư.
   */
  public function findSupplies(array $names) {
    $name_map = [];
    $supplie_storage = $this->entityTypeManager->getStorage("supplies");
    $query = $supplie_storage->getQuery()->accessCheck(TRUE);

    $or = $query->orConditionGroup()
      ->condition("title", $names, "IN")
      ->condition("field_sup_alias_names", $names, "IN");

    $query->condition($or);
    $ids = $query->execute();

    $supplies = $supplie_storage->loadMultiple($ids);

    /** @var \Drupal\eck\Entity\EckEntity $supply */
    foreach ($supplies as $supply) {
      $name_map[$supply->label()] = $supply->id();
      foreach ($supply->get("field_sup_alias_names") as $alias) {
        $name_map[$alias->value] = $supply->id();
      }
    }

    return $name_map;
  }

  /**
   * Tìm tên công ty.
   */
  public function findCompanies(string $name) {
    $name_map = [];
    $company_storage = $this->entityTypeManager->getStorage("taxonomy_term");
    $query = $company_storage->getQuery()
      ->condition("vid", "company")
      ->accessCheck(TRUE);

    $or = $query->orConditionGroup()
      ->condition("name", $name)
      ->condition("field_company_alias_names", $name);

    $query->condition($or);
    $ids = $query->execute();

    $companys = $company_storage->loadMultiple($ids);

    /** @var \Drupal\taxonomy\Entity\Term $supply */
    foreach ($companys as $supply) {
      $name_map[$supply->label()] = $supply->id();
      foreach ($supply->get("field_company_alias_names") as $alias) {
        $name_map[$alias->value] = $supply->id();
      }
    }

    return $name_map;
  }

  /**
   * Tìm tên nhà cung cấp / khách hàng.
   */
  public function findSupplier(string $name) {
    $supplie_storage = $this->entityTypeManager->getStorage("crm");
    $query = $supplie_storage->getQuery()->accessCheck(TRUE);

    $or = $query->orConditionGroup()
      ->condition("title", $name)
      ->condition("field_customer_alias_names", $name);

    $query->condition("type", "customers");
    $query->condition($or);
    $ids = $query->execute();

    return reset($ids);
  }

  /**
   * Lấy thông tin kho hàng theo công ty.
   */
  public function findWarehouse() {
    $taxonomy_storage = $this->entityTypeManager->getStorage("taxonomy_term");
    $terms = $taxonomy_storage->loadByProperties([
      "vid" => "warehouse",
      "name" => "Tổng hóa đơn điện tử",
    ]);
    $term = reset($terms);
    return !empty($term) ? $term->id() : NULL;
  }

  /**
   * Load taxonomy.
   */
  public function loadTerm($type = "unit", $key = "name", $field = "name") {
    $list_item = [];

    $terms = $this->entityTypeManager
      ->getStorage("taxonomy_term")
      ->loadByProperties(["vid" => $type]);

    /** @var \Drupal\taxonomy\Entity\Term $term */
    if ($key == "name") {
      foreach ($terms as $term) {
        $list_item[mb_strtolower($term->label(), "UTF-8")] = $term->id();
      }
    }
    else {
      foreach ($terms as $term) {
        $list_item[$term->id()] = $field == "name"
          ? $term->label()
          : $term->get($field)->value;
      }
    }

    return $list_item;
  }

  /**
   * Nhập hàng hóa đơn.
   */
  public function createImport(InvoiceInterface $invoice, string|int $contact_id, string|int $warehouse_id, array $units) {
    $transaction = $this->connection->startTransaction();

    try {
      $paragraphs = [];
      $date = date("Y-m-d");
      $company = $invoice->get("field_invoice_company")->target_id;
      $total_amount = $invoice->get("field_invoice_total_amount")->value;
      $list_items = $invoice->get("field_invoice_items")->getValue();

      $supplies = $this->entityTypeManager
        ->getStorage("supplies")
        ->loadMultiple(array_column($list_items, "item_id"));

      /** @var \Drupal\node\Entity\Node $node */
      $node = $this->entityTypeManager
        ->getStorage("node")
        ->create([
          "type" => "import_warehouse",
          "title" => $this->t("Import goods for") . " [" . $this->t("Invoice") . " " . $invoice->get("field_invoice_no")->value . "]",
          "field_wsh_client" => $contact_id,
          "field_wsh_export" => $warehouse_id,
          "field_wsh_total_tax" => $invoice->get("field_invoice_vat_amount"),
          "field_wsh_total_discount" => $invoice->get("field_invoice_discount_amount"),
          "field_wsh_total_excl_tax" => $total_amount,
          "field_iw_status" => "complete",
          "field_iw_type" => "einvoice",
          "field_public" => FALSE,
          "field_company" => $company,
          "field_e_invoice" => $invoice->id(),
        ]);

      foreach ($list_items as $item) {

        if (empty($item["item_id"])) {
          continue;
        }

        /** @var \Drupal\paragraphs\Entity\Paragraph $history_debt */
        $history_debt = $this->entityTypeManager
          ->getStorage("paragraph")
          ->create([
            "type" => "warehouse_content",
            "field_wc_code" => $item["item_code"],
            "field_wc_supplies" => $item["item_id"],
            "field_wc_unit" => $units[mb_strtolower($item["item_unit"], "UTF-8")] ?? NULL,
            "field_wc_quantity_import" => $item["item_quantity"],
            "field_wc_price" => $item["item_price"],
            "field_wc_excl_tax" => $item["item_vat_amount"],
          ]);

        $history_debt->save();

        $paragraphs[] = [
          "target_id" => $history_debt->id(),
          "target_revision_id" => $history_debt->getRevisionId(),
        ];
      }

      if (!empty($paragraphs)) {

        $node->set("field_wsh_content_storage", $paragraphs);
        $node->save();

        foreach ($list_items as $item) {

          if (empty($item["item_id"]) || $item["item_exist"] == 2) {
            continue;
          }

          $supplie = $supplies[$item["item_id"]];
          $quantity = $item["item_quantity"];

          $this->createWarehouse($supplie, $warehouse_id, $quantity, "import");
          $this->createImportHistory($supplie, $invoice->id(), $node->id(), $warehouse_id, $item, $date);
        }

        $invoice->set("field_invoice_import", TRUE);
        $invoice->save();
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->loggerFactory->get("erp_e_invoice")->error($e->getMessage());
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Xuất hàng hóa đơn.
   */
  public function createExport(InvoiceInterface $invoice, string|int $contact_id, string|int $warehouse_id, array $units) {
    $transaction = $this->connection->startTransaction();
    try {
      $paragraphs = [];
      $date = date("Y-m-d");
      $company = $invoice->get("field_invoice_company")->target_id;
      $total_amount = $invoice->get("field_invoice_total_amount")->value;
      $list_items = $invoice->get("field_invoice_items")->getValue();

      $supplies = $this->entityTypeManager
        ->getStorage("supplies")
        ->loadMultiple(array_column($list_items, "item_id"));

      // Tạo phiếu xuất hàng.
      /** @var \Drupal\node\Entity\Node $node */
      $node = $this->entityTypeManager
        ->getStorage("node")
        ->create([
          "type" => "warehouse_storage_history",
          "title" => $this->t("Export goods for") . " [" . $this->t("Invoice") . " " . $invoice->get("field_invoice_no")->value . "]",
          "field_wsh_client" => $contact_id,
          "field_wsh_export" => $warehouse_id,
          "field_wsh_total_tax" => $invoice->get("field_invoice_vat_amount"),
          "field_wsh_total_discount" => $invoice->get("field_invoice_discount_amount"),
          "field_wsh_total_excl_tax" => $total_amount,
          "field_wsh_voucher_status" => "complete",
          "field_wsh_type" => "einvoice",
          "field_public" => FALSE,
          "field_company" => $company,
          "field_e_invoice" => $invoice->id(),
        ]);

      foreach ($list_items as $item) {

        if (empty($item["item_id"])) {
          continue;
        }

        // Tạo nội dung xuất hàng.
        /** @var \Drupal\paragraphs\Entity\Paragraph $history_debt */
        $history_debt = $this->entityTypeManager
          ->getStorage("paragraph")
          ->create([
            "type" => "warehouse_content",
            "field_wc_code" => $item["item_code"],
            "field_wc_supplies" => $item["item_id"],
            "field_wc_unit" => !empty($item["item_unit"]) ? $units[mb_strtolower($item["item_unit"], "UTF-8")] : NULL,
            "field_wc_quantity_export" => $item["item_quantity"],
            "field_wc_price" => $item["item_price"],
            "field_wc_excl_tax" => $item["item_vat_amount"],
          ]);

        $history_debt->save();

        $paragraphs[] = [
          "target_id" => $history_debt->id(),
          "target_revision_id" => $history_debt->getRevisionId(),
        ];
      }

      if (!empty($paragraphs)) {

        $node->set("field_wsh_content_storage", $paragraphs);
        $node->save();

        foreach ($list_items as $item) {

          if (empty($item["item_id"]) || $item["item_exist"] == 2) {
            continue;
          }

          $supplie = $supplies[$item["item_id"]];
          $quantity = $item["item_quantity"];

          $this->createWarehouse($supplie, $warehouse_id, $quantity, "export");
          $this->createExportHistory($supplie, $invoice->id(), $node->id(), $warehouse_id, $item, $date);
        }

        $invoice->set("field_invoice_export", TRUE);
        $invoice->save();
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->loggerFactory->get("erp_e_invoice")->error($e->getMessage());
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Cập nhật kho hàng.
   */
  private function createWarehouse($supplie, $warehouse, $quantity, $type) {
    $paragraph_storage = $this->entityTypeManager->getStorage("paragraph");

    $paragraph = $paragraph_storage->getQuery()
      ->condition("type", "warehouse")
      ->condition("parent_id", $supplie->id())
      ->condition("field_warehouse", $warehouse)
      ->accessCheck(TRUE)
      ->range(0, 1);

    $ids = $paragraph->execute();

    if (!empty($ids)) {
      /** @var \Drupal\paragraphs\Entity\Paragraph $para_warehouse */
      $para_warehouse = $paragraph_storage->load(reset($ids));
      $current = $para_warehouse->get("field_warehouse_quantity")->value ?? 0;
      $new_quantity = $type === "import" ? ($current + $quantity) : ($current - $quantity);
      $para_warehouse->set("field_warehouse_quantity", $new_quantity);
      $para_warehouse->save();
    }
    else {
      $new_quantity = $type === "import" ? ($quantity) : (0 - $quantity);
      /** @var \Drupal\paragraphs\Entity\Paragraph $para_warehouse */
      $para_warehouse = $this->entityTypeManager
        ->getStorage("paragraph")
        ->create([
          "type" => "warehouse",
          "field_warehouse" => $warehouse,
          "field_warehouse_quantity" => $new_quantity,
        ]);
      $para_warehouse->save();

      $supplie->get("field_sup_list_warehouse")->appendItem([
        "target_id" => $warehouse,
      ]);
      $supplie->get("field_sup_warehouse")->appendItem([
        "target_id" => $para_warehouse->id(),
        "target_revision_id" => $para_warehouse->getRevisionId(),
      ]);

      $supplie->save();
    }
  }

  /**
   * Lịch sử nhập kho hàng.
   */
  private function createImportHistory($supplie, $invoice, $import, $warehouse, $item, $date) {
    /** @var \Drupal\paragraphs\Entity\Paragraph $para_warehouse */
    $para_warehouse = $this->entityTypeManager
      ->getStorage("paragraph")
      ->create([
        "type" => "import_history",
        "field_ih_invoice" => $invoice,
        "field_ih_receipt" => $import,
        "field_ih_warehouse" => $warehouse,
        "field_ih_quantity" => $item["item_quantity"],
        "field_ih_quantity_remaining" => $item["item_quantity"],
        "field_ih_purchase_price" => $item["item_price"],
        "field_ih_received_date" => $date,
        "field_document_type" => "e_invoice"
      ]);
    $para_warehouse->save();

    $supplie->get("field_sup_import_history")->appendItem([
      "target_id" => $para_warehouse->id(),
      "target_revision_id" => $para_warehouse->getRevisionId(),
    ]);

    $supplie->save();
  }

  /**
   * Lịch sử xuất kho hàng.
   */
  private function createExportHistory($supplie, $invoice, $export, $warehouse, $item, $date) {
    /** @var \Drupal\paragraphs\Entity\Paragraph $para_warehouse */
    $para_warehouse = $this->entityTypeManager
      ->getStorage("paragraph")
      ->create([
        "type" => "history_export",
        "field_he_invoice" => $invoice,
        "field_he_export" => $export,
        "field_he_warehouse" => $warehouse,
        "field_he_output_quantity" => $item["item_quantity"],
        "field_he_price" => $item["item_price"],
        "field_he_date_export" => $date,
        "field_document_type" => "e_invoice"
      ]);
    $para_warehouse->save();

    $supplie->get("field_sup_export_history")->appendItem([
      "target_id" => $para_warehouse->id(),
      "target_revision_id" => $para_warehouse->getRevisionId(),
    ]);

    $supplie->save();
  }

  /**
   * Danh sách công ty cha con.
   */
  public function getDescendantTids($parent_tid, $termStorage) {
    $tree = $termStorage->loadTree("company", $parent_tid);
    $tids = [$parent_tid => $parent_tid];

    foreach ($tree as $term) {
      $tids[$term->tid] = $term->tid;
    }

    return $tids;
  }

  /**
   * Danh sách công ty kiêm nghiêm.
   */
  public function getCurrentPosition($user) {
    $company_return = [];
    $concurrent_position_entities = $user
      ->get("field_user_concurrent_position")
      ->referencedEntities();

    foreach ($concurrent_position_entities as $concurrent_position_entity) {
      $term_entity = $concurrent_position_entity
        ->get("field_ucp_company")
        ->entity;

      if (!$term_entity) {
        continue;
      }

      $company_return[$term_entity->id()] = $term_entity->label();
    }

    return $company_return;
  }

}

<?php

namespace Drupal\erp_e_invoice;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   * Số dòng mỗi trang cho bảng danh sách hóa đơn.
   *
   * Truy vấn lấy toàn bộ hóa đơn khớp bộ lọc, việc chia trang do trình duyệt
   * xử lý nên đây chỉ là danh sách gợi ý cho ô chọn số dòng mỗi trang.
   */
  public const PAGE_SIZES = [20, 50, 100, 200, 500, 1000];

  /**
   * Số dòng mỗi trang mặc định khi người dùng chưa chọn.
   */
  public const DEFAULT_PAGE_SIZE = 50;

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
   * @param ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param object|null $commonService
   *   Dịch vụ sinh số hiệu chứng từ dùng chung, có thể vắng mặt.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected MessengerInterface $messenger,
    protected GetConfigInvoice $config,
    protected Connection $connection,
    protected KeyValueFactoryInterface $keyValue,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ConfigFactoryInterface $configFactory,
    protected ?object $commonService = NULL,
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

    $invoice_ids = $invoices_query->execute();
    $list_invoices = $invoiceManage->loadMultiple($invoice_ids);

    $summary = $this->sumInvoice($list_invoices);

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
      "page_size" => self::DEFAULT_PAGE_SIZE,
      "option_page_size" => self::PAGE_SIZES,
      "summary" => $summary,
      "date" => [
        "start" => $start_date,
        "end" => $end_date,
      ],
    ];
  }

  /**
   * Cộng tổng tiền của toàn bộ hóa đơn khớp bộ lọc.
   */
  public function sumInvoice(array $invoices): array {
    $summary = [
      "count" => count($invoices),
      "amount_without_vat" => 0,
      "vat_amount" => 0,
      "total_amount" => 0,
    ];

    /** @var InvoiceInterface $invoice */
    foreach ($invoices as $invoice) {
      foreach (self::SUM_FIELDS as $key => $field) {
        $summary[$key] += (float) ($invoice->get($field)->value ?? 0);
      }
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

    if ($key == "name") {
      /** @var \Drupal\taxonomy\Entity\Term $term */
      foreach ($terms as $term) {
        $list_item[mb_strtolower($term->label(), "UTF-8")] = $term->id();
      }
    }
    else {
      /** @var \Drupal\taxonomy\Entity\Term $term */
      foreach ($terms as $term) {
        $list_item[$term->id()] = $field == "name"
          ? $term->label()
          : $term->get($field)->value;
      }
    }

    return $list_item;
  }

  /**
   * Cấu hình phiếu kho theo chiều nhập / xuất.
   *
   * Hai luồng chỉ khác nhau ở bundle phiếu và tên vài trường, phần còn lại
   * (đối chiếu vật tư, cộng trừ tồn kho, ghi lịch sử) dùng chung.
   */
  private const DOCUMENT_SETTINGS = [
    "import" => [
      "node_bundle" => "import_warehouse",
      "status_field" => "field_iw_status",
      "type_field" => "field_iw_type",
      "code_field" => "field_iw_code",
      "code_pattern" => "pattern_import_warehouse",
      "quantity_field" => "field_wc_quantity_import",
      "invoice_field" => "field_invoice_import",
      "contact_field" => "field_invoice_seller_name",
      "history_bundle" => "import_history",
      "history_field" => "field_sup_import_history",
    ],
    "export" => [
      "node_bundle" => "warehouse_storage_history",
      "status_field" => "field_wsh_voucher_status",
      "type_field" => "field_wsh_type",
      "code_field" => "field_wsh_number",
      "code_pattern" => "pattern_warehouse_storage_history",
      "quantity_field" => "field_wc_quantity_export",
      "invoice_field" => "field_invoice_export",
      "contact_field" => "field_invoice_buyer_name",
      "history_bundle" => "history_export",
      "history_field" => "field_sup_export_history",
    ],
  ];

  /**
   * Vật tư đã đối chiếu xong và được phép ghi kho.
   */
  private const ITEM_MATCHED = 1;

  /**
   * Nhập hàng hóa đơn.
   *
   * @return bool
   *   TRUE khi đã tạo phiếu nhập, FALSE khi không có gì để nhập hoặc lỗi.
   */
  public function createImport(InvoiceInterface $invoice, string|int $contact_id, string|int $warehouse_id, array $units): bool {
    return $this->createDocument("import", $invoice, $contact_id, $warehouse_id, $units);
  }

  /**
   * Xuất hàng hóa đơn.
   *
   * @return bool
   *   TRUE khi đã tạo phiếu xuất, FALSE khi không có gì để xuất hoặc lỗi.
   */
  public function createExport(InvoiceInterface $invoice, string|int $contact_id, string|int $warehouse_id, array $units): bool {
    return $this->createDocument("export", $invoice, $contact_id, $warehouse_id, $units);
  }

  /**
   * Đối chiếu vật tư rồi tạo phiếu nhập / xuất cho hóa đơn vừa kéo về.
   *
   * Dùng cho luồng tự động: chỉ tạo phiếu khi mọi dòng hóa đơn đều khớp được
   * vật tư và tìm được đối tác, còn lại để kế toán xử lý tay trên form.
   *
   * @param InvoiceInterface $invoice
   *   Hóa đơn cần xử lý.
   * @param string $type
   *   "import" hoặc "export".
   *
   * @return bool
   *   TRUE khi đã tạo được phiếu.
   */
  public function autoCreateDocument(InvoiceInterface $invoice, string $type): bool {
    $settings = self::DOCUMENT_SETTINGS[$type] ?? NULL;

    if ($settings === NULL) {
      return FALSE;
    }

    if ($this->isHandled($invoice, $settings["invoice_field"])) {
      return FALSE;
    }

    $list_items = $invoice->get("field_invoice_items")->getValue();
    $supplies = $this->findSupplies(array_column($list_items, "item_name"));

    $matched = TRUE;
    foreach ($list_items as $key => $item) {
      $supply_id = $supplies[$item["item_name"] ?? ""] ?? NULL;

      $list_items[$key]["item_exist"] = $supply_id ? self::ITEM_MATCHED : 0;
      $list_items[$key]["item_id"] = $supply_id;

      $matched = $matched && $supply_id;
    }

    $invoice->set("field_invoice_items", $list_items);
    $invoice->save();

    $contact_id = $this->findSupplier((string) $invoice->get($settings["contact_field"])->value);

    // Còn dòng chưa khớp vật tư hoặc chưa có đối tác: chờ kế toán đối chiếu.
    if (!$matched || empty($list_items) || empty($contact_id)) {
      return FALSE;
    }

    $warehouse_id = $this->findWarehouse();
    if (empty($warehouse_id)) {
      $this->messenger->addError($this->t("Not found e-invoice warehouse"));
      return FALSE;
    }

    return $this->createDocument($type, $invoice, $contact_id, $warehouse_id, $this->loadTerm());
  }

  /**
   * Tạo phiếu kho từ hóa đơn.
   *
   * @param string $type
   *   "import" hoặc "export".
   * @param InvoiceInterface $invoice
   *   Hóa đơn nguồn.
   * @param string|int $contact_id
   *   Nhà cung cấp (hóa đơn vào) hoặc khách hàng (hóa đơn ra).
   * @param string|int $warehouse_id
   *   Kho hàng điện tử.
   * @param array $units
   *   Bản đồ tên đơn vị tính đã hạ chữ thường sang term id.
   *
   * @return bool
   *   TRUE khi phiếu được tạo.
   */
  private function createDocument(string $type, InvoiceInterface $invoice, string|int $contact_id, string|int $warehouse_id, array $units): bool {
    $settings = self::DOCUMENT_SETTINGS[$type] ?? NULL;

    if ($settings === NULL) {
      return FALSE;
    }

    // Chốt chặn cuối: hóa đơn đã nhập / xuất rồi thì không ghi kho lần nữa.
    if ($this->isHandled($invoice, $settings["invoice_field"])) {
      $this->loggerFactory->get("erp_e_invoice")->warning(
        "Hóa đơn @id đã được xử lý kho, bỏ qua yêu cầu tạo phiếu.",
        ["@id" => $invoice->id()]
      );
      return FALSE;
    }

    $lines = $this->documentLines($invoice);
    if (empty($lines)) {
      return FALSE;
    }

    $supplies = $this->entityTypeManager
      ->getStorage("supplies")
      ->loadMultiple(array_unique(array_column($lines, "item_id")));

    // Vật tư đã bị xoá sau khi đối chiếu thì bỏ dòng đó ra khỏi phiếu.
    $lines = array_filter($lines, fn (array $item) => isset($supplies[$item["item_id"]]));
    if (empty($lines)) {
      return FALSE;
    }

    $transaction = $this->connection->startTransaction();

    try {
      $date = date("Y-m-d");
      $paragraphs = [];
      $quantities = [];

      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->entityTypeManager
        ->getStorage("node")
        ->create([
          "type" => $settings["node_bundle"],
          "title" => ($type === "import" ? $this->t("Import goods for") : $this->t("Export goods for"))
            . " [" . $this->t("Invoice") . " " . $invoice->get("field_invoice_no")->value . "]",
          "field_wsh_client" => $contact_id,
          "field_wsh_export" => $warehouse_id,
          "field_wsh_total_tax" => $this->amount($invoice, "field_invoice_vat_amount"),
          "field_wsh_total_discount" => $this->amount($invoice, "field_invoice_discount_amount"),
          // Quy ước sẵn có của hệ thống: excl_tax giữ tổng thanh toán, còn
          // incl_tax giữ tiền hàng trước thuế.
          "field_wsh_total_excl_tax" => $this->amount($invoice, "field_invoice_total_amount"),
          "field_wsh_total_incl_tax" => $this->amount($invoice, "field_invoice_amount_without_vat"),
          $settings["status_field"] => "complete",
          $settings["type_field"] => "einvoice",
          "field_public" => FALSE,
          "field_company" => $invoice->get("field_invoice_company")->target_id,
          "field_e_invoice" => $invoice->id(),
        ]);

      foreach ($lines as $item) {
        $unit = !empty($item["item_unit"]) ? mb_strtolower($item["item_unit"], "UTF-8") : NULL;
        $quantity = (float) ($item["item_quantity"] ?? 0);

        /** @var \Drupal\paragraphs\Entity\Paragraph $content */
        $content = $this->entityTypeManager
          ->getStorage("paragraph")
          ->create([
            "type" => "warehouse_content",
            "field_wc_code" => $item["item_code"] ?? NULL,
            "field_wc_supplies" => $item["item_id"],
            "field_wc_unit" => $units[$unit] ?? NULL,
            $settings["quantity_field"] => $quantity,
            "field_wc_price" => $item["item_price"] ?? 0,
            // Tên trường ngược nghĩa nhưng đây là quy ước đang dùng chung:
            // excl_tax là tiền thuế, incl_tax là thành tiền trước thuế.
            "field_wc_excl_tax" => $item["item_vat_amount"] ?? 0,
            "field_wc_incl_tax" => $item["item_amount_without_vat"] ?? 0,
          ]);

        $content->save();

        $paragraphs[] = [
          "target_id" => $content->id(),
          "target_revision_id" => $content->getRevisionId(),
        ];

        // Một vật tư có thể nằm trên nhiều dòng hóa đơn, cộng dồn trước rồi
        // mới ghi kho một lần để không tạo trùng bản ghi tồn kho.
        $quantities[$item["item_id"]] = ($quantities[$item["item_id"]] ?? 0) + $quantity;
      }

      $node->set("field_wsh_content_storage", $paragraphs);
      $node->save();

      $this->setDocumentCode($node, $settings["code_field"], $settings["code_pattern"]);

      foreach ($lines as $item) {
        $this->appendHistory($supplies[$item["item_id"]], $type, $invoice->id(), $node->id(), $warehouse_id, $item, $date);
      }

      foreach ($quantities as $supply_id => $quantity) {
        $this->updateWarehouseQuantity($supplies[$supply_id], $warehouse_id, $quantity, $type);
      }

      // Mỗi vật tư chỉ lưu một lần sau khi đã gắn đủ tồn kho và lịch sử.
      foreach ($supplies as $supply) {
        $supply->save();
      }

      $invoice->set($settings["invoice_field"], 1);
      $invoice->save();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->loggerFactory->get("erp_e_invoice")->error($e->getMessage());
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Các dòng hóa đơn đủ điều kiện ghi kho.
   *
   * Bỏ dòng chưa đối chiếu được vật tư và dòng kế toán chọn không nhập / xuất
   * (item_exist = 2) — trước đây dòng loại này vẫn lọt vào nội dung phiếu.
   */
  private function documentLines(InvoiceInterface $invoice): array {
    $lines = [];

    foreach ($invoice->get("field_invoice_items")->getValue() as $item) {
      if (empty($item["item_id"]) || (int) ($item["item_exist"] ?? 0) !== self::ITEM_MATCHED) {
        continue;
      }

      $lines[] = $item;
    }

    return $lines;
  }

  /**
   * Hóa đơn đã được nhập kho / xuất kho hoặc đã đánh dấu bỏ qua chưa.
   *
   * Giá trị trường list_integer đọc từ CSDL là chuỗi nên phải so sánh theo số.
   */
  public function isHandled(InvoiceInterface $invoice, string $field): bool {
    if (!$invoice->hasField($field) || $invoice->get($field)->isEmpty()) {
      return FALSE;
    }

    return in_array((int) $invoice->get($field)->value, [1, 2], TRUE);
  }

  /**
   * Đọc giá trị tiền của hóa đơn, trả 0 khi bundle không có trường.
   */
  private function amount(InvoiceInterface $invoice, string $field): float {
    return $invoice->hasField($field)
      ? (float) ($invoice->get($field)->value ?? 0)
      : 0;
  }

  /**
   * Sinh số phiếu theo mẫu dùng chung của hệ thống kho.
   *
   * Hàm đánh số của module kho chỉ chạy khi lưu phiếu bằng form nên phiếu tạo
   * từ hóa đơn sẽ trống số nếu không gọi lại ở đây.
   */
  private function setDocumentCode($node, string $field, string $pattern_key): void {
    if (!$node->hasField($field) || !$node->get($field)->isEmpty()) {
      return;
    }

    if (!$this->commonService) {
      return;
    }

    $patterns = $this->configFactory->get("erp_common.link_settings_overall");
    $pattern = $patterns->get($pattern_key) ?: $patterns->get("pattern_overall");

    if (empty($pattern)) {
      return;
    }

    $node->set($field, $this->commonService->generateTitleOverall($pattern, $node, $field));
    $node->save();
  }

  /**
   * Cộng / trừ tồn kho của vật tư trong kho hóa đơn điện tử.
   *
   * Không tự lưu vật tư: bên gọi lưu một lần sau khi ghi xong mọi thay đổi.
   */
  private function updateWarehouseQuantity($supplie, $warehouse, $quantity, $type): void {
    $paragraph_storage = $this->entityTypeManager->getStorage("paragraph");

    // Bản ghi tồn kho là dữ liệu nội bộ, không lọc theo quyền của người dùng
    // đang thao tác — lọc quyền sẽ tạo ra bản ghi tồn kho trùng.
    $ids = $paragraph_storage->getQuery()
      ->condition("type", "warehouse")
      ->condition("parent_type", $supplie->getEntityTypeId())
      ->condition("parent_id", $supplie->id())
      ->condition("field_warehouse", $warehouse)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    $change = $type === "import" ? $quantity : -$quantity;

    if (!empty($ids)) {
      /** @var \Drupal\paragraphs\Entity\Paragraph $para_warehouse */
      $para_warehouse = $paragraph_storage->load(reset($ids));
      $current = (float) ($para_warehouse->get("field_warehouse_quantity")->value ?? 0);
      $para_warehouse->set("field_warehouse_quantity", $current + $change);
      $para_warehouse->save();

      return;
    }

    /** @var \Drupal\paragraphs\Entity\Paragraph $para_warehouse */
    $para_warehouse = $paragraph_storage->create([
      "type" => "warehouse",
      "field_warehouse" => $warehouse,
      "field_warehouse_quantity" => $change,
    ]);
    $para_warehouse->save();

    $supplie->get("field_sup_list_warehouse")->appendItem([
      "target_id" => $warehouse,
    ]);
    $supplie->get("field_sup_warehouse")->appendItem([
      "target_id" => $para_warehouse->id(),
      "target_revision_id" => $para_warehouse->getRevisionId(),
    ]);
  }

  /**
   * Ghi lịch sử nhập / xuất kho cho vật tư.
   *
   * Không tự lưu vật tư: bên gọi lưu một lần sau khi ghi xong mọi thay đổi.
   */
  private function appendHistory($supplie, string $type, $invoice, $document, $warehouse, array $item, string $date): void {
    $settings = self::DOCUMENT_SETTINGS[$type];

    $values = $type === "import"
      ? [
        "field_ih_invoice" => $invoice,
        "field_ih_receipt" => $document,
        "field_ih_warehouse" => $warehouse,
        "field_ih_quantity" => $item["item_quantity"] ?? 0,
        "field_ih_quantity_remaining" => $item["item_quantity"] ?? 0,
        "field_ih_purchase_price" => $item["item_price"] ?? 0,
        "field_ih_received_date" => $date,
      ]
      : [
        "field_he_invoice" => $invoice,
        "field_he_export" => $document,
        "field_he_warehouse" => $warehouse,
        "field_he_output_quantity" => $item["item_quantity"] ?? 0,
        "field_he_price" => $item["item_price"] ?? 0,
        "field_he_date_export" => $date,
      ];

    /** @var \Drupal\paragraphs\Entity\Paragraph $history */
    $history = $this->entityTypeManager
      ->getStorage("paragraph")
      ->create([
        "type" => $settings["history_bundle"],
        "field_document_type" => "e_invoice",
      ] + $values);

    $history->save();

    $supplie->get($settings["history_field"])->appendItem([
      "target_id" => $history->id(),
      "target_revision_id" => $history->getRevisionId(),
    ]);
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

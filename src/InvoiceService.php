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
   * @param WarehouseService $warehouseService
   *   Dịch vụ ghi và đọc kho hàng.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected MessengerInterface $messenger,
    protected GetConfigInvoice $config,
    protected Connection $connection,
    protected KeyValueFactoryInterface $keyValue,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected WarehouseService $warehouseService,
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

    // Người dùng xoá trắng ô ngày để tìm theo tên trên toàn bộ hóa đơn, nên ô
    // ngày rỗng phải hiểu là bỏ giới hạn. Chỉ lần đầu mở trang (chưa gửi ô ngày
    // nào) mới lấy mặc định tháng này.
    $has_date_filter = $request->query->has("start_date") || $request->query->has("end_date");

    $start_date = $request->query->get("start_date")
      ?: ($has_date_filter ? NULL : date("Y-m-01"));

    $end_date = $request->query->get("end_date")
      ?: ($has_date_filter ? NULL : date("Y-m-d"));

    $import = $request->query->get("import");
    $export = $request->query->get("export");
    $status = $request->query->get("status") ?? "";
    $sell_name = $request->query->get("sell_name") ?? "";
    $buy_name = $request->query->get("buy_name") ?? "";

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

    if (!empty($sell_name)) {
      $invoices_query->condition('field_invoice_seller_name', '%' . $sell_name . '%', 'LIKE');
    }

    if (!empty($buy_name)) {
      $invoices_query->condition('field_invoice_buyer_name', '%' . $buy_name . '%', 'LIKE');
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
      "sell_name" => $sell_name,
      "buy_name" => $buy_name,
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

    if (!$entity) {
      return NULL;
    }

    // Dịch vụ, tài sản... không có trường tên gọi khác nhưng vẫn là vật tư hợp
    // lệ: trả id để dòng hóa đơn được ghi kho, chỉ bỏ phần lưu tên gọi khác.
    if (!$entity->hasField($field)) {
      return $entity->id();
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

      // Chỉ bundle "supplies" có trường tên gọi khác, đọc thẳng ở bundle khác
      // (dịch vụ, tài sản) sẽ ném ngoại lệ và làm hỏng cả lượt kéo hóa đơn.
      if (!$supply->hasField("field_sup_alias_names")) {
        continue;
      }

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
   * Tìm nhiều nhà cung cấp / khách hàng theo tên trong một truy vấn.
   *
   * Dùng cho luồng xử lý nhiều hóa đơn cùng lúc: mỗi hóa đơn một tên đối tác
   * nên tra từng tên sẽ tốn đúng bằng số hóa đơn truy vấn.
   *
   * @param array $names
   *   Danh sách tên đối tác đọc từ hóa đơn.
   *
   * @return array
   *   Tên (kể cả tên gọi khác) ánh xạ sang id khách hàng.
   */
  public function findSuppliers(array $names): array {
    $names = array_values(array_filter($names));

    if (empty($names)) {
      return [];
    }

    $name_map = [];
    $customer_storage = $this->entityTypeManager->getStorage("crm");
    $query = $customer_storage->getQuery()->accessCheck(TRUE);

    $or = $query->orConditionGroup()
      ->condition("title", $names, "IN")
      ->condition("field_customer_alias_names", $names, "IN");

    $query->condition("type", "customers");
    $query->condition($or);
    $ids = $query->execute();

    $customers = $customer_storage->loadMultiple($ids);

    /** @var \Drupal\eck\Entity\EckEntity $customer */
    foreach ($customers as $customer) {
      $name_map[$customer->label()] = $customer->id();

      if (!$customer->hasField("field_customer_alias_names")) {
        continue;
      }

      foreach ($customer->get("field_customer_alias_names") as $alias) {
        $name_map[$alias->value] = $customer->id();
      }
    }

    return $name_map;
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
   * Kho hàng mặc định để ghi hóa đơn của một công ty.
   *
   * Chỉ trả về kho khi công ty có đúng một kho: nhiều kho thì không đoán thay
   * kế toán được, để form đối chiếu hỏi.
   *
   * @param string|int|null $company_id
   *   Term công ty của hóa đơn.
   *
   * @return string|int|null
   *   Id kho hàng, NULL khi chưa có kho hoặc có nhiều kho.
   */
  public function findWarehouse(string|int|null $company_id = NULL) {
    $warehouses = $this->warehouseService->loadWarehouses(
      $company_id ? [$company_id] : []
    );

    if (count($warehouses) !== 1) {
      return NULL;
    }

    return array_key_first($warehouses);
  }

  /**
   * Cấu hình luồng nhập / xuất.
   *
   * Hai chiều chỉ khác nhau ở cờ đánh dấu trên hóa đơn và tên bên đối tác,
   * phần ghi kho dùng chung qua WarehouseService.
   */
  private const DOCUMENT_SETTINGS = [
    "import" => [
      "invoice_field" => "field_invoice_import",
      "contact_field" => "field_invoice_seller_name",
    ],
    "export" => [
      "invoice_field" => "field_invoice_export",
      "contact_field" => "field_invoice_buyer_name",
    ],
  ];

  /**
   * Vật tư đã đối chiếu xong và được phép ghi kho.
   */
  private const ITEM_MATCHED = 1;

  /**
   * Nhập hàng hóa đơn.
   *
   * @param InvoiceInterface $invoice
   *   Hóa đơn nguồn.
   * @param string|int|null $contact_id
   *   Nhà cung cấp.
   * @param string|int $warehouse_id
   *   Kho hàng (entity warehouse_invoice).
   * @param string|null $date
   *   Ngày ghi sổ, mặc định là ngày hóa đơn.
   *
   * @return bool
   *   TRUE khi đã tạo phiếu nhập, FALSE khi không có gì để nhập hoặc lỗi.
   */
  public function createImport(InvoiceInterface $invoice, string|int|null $contact_id, string|int $warehouse_id, ?string $date = NULL): bool {
    return $this->createDocument("import", $invoice, $contact_id, $warehouse_id, $date);
  }

  /**
   * Xuất hàng hóa đơn.
   *
   * @param InvoiceInterface $invoice
   *   Hóa đơn nguồn.
   * @param string|int|null $contact_id
   *   Khách hàng.
   * @param string|int $warehouse_id
   *   Kho hàng (entity warehouse_invoice).
   * @param string|null $date
   *   Ngày ghi sổ, mặc định là ngày hóa đơn.
   *
   * @return bool
   *   TRUE khi đã tạo phiếu xuất, FALSE khi không có gì để xuất hoặc lỗi.
   */
  public function createExport(InvoiceInterface $invoice, string|int|null $contact_id, string|int $warehouse_id, ?string $date = NULL): bool {
    return $this->createDocument("export", $invoice, $contact_id, $warehouse_id, $date);
  }

  /**
   * Đối chiếu vật tư rồi tạo phiếu nhập / xuất cho hóa đơn vừa kéo về.
   *
   * Dùng cho luồng tự động: chỉ tạo phiếu khi mọi dòng hóa đơn đều khớp được
   * vật tư, tìm được đối tác và công ty chỉ có một kho, còn lại để kế toán xử
   * lý tay trên form đối chiếu.
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

    $warehouse_id = $this->findWarehouse($invoice->get("field_invoice_company")->target_id);

    // Chưa có kho hoặc có nhiều kho thì phải để kế toán chọn, không tự ghi.
    if (empty($warehouse_id)) {
      return FALSE;
    }

    // Kỳ đã khoá thì phiếu ghi thêm sẽ làm lệch tồn đầu kỳ sau, đây là việc kế
    // toán phải quyết chứ không để luồng tự động âm thầm ghi vào.
    $period = $this->warehouseService->findPeriod(
      $warehouse_id,
      $this->warehouseService->invoiceDate($invoice)
    );

    if ($period && $this->warehouseService->isPeriodClosed($period)) {
      return FALSE;
    }

    return $this->createDocument($type, $invoice, $contact_id, $warehouse_id);
  }

  /**
   * Tạo phiếu kho từ hóa đơn.
   *
   * @param string $type
   *   "import" hoặc "export".
   * @param InvoiceInterface $invoice
   *   Hóa đơn nguồn.
   * @param string|int|null $contact_id
   *   Nhà cung cấp (hóa đơn vào) hoặc khách hàng (hóa đơn ra).
   * @param string|int $warehouse_id
   *   Kho hàng (entity warehouse_invoice).
   * @param string|null $date
   *   Ngày ghi sổ, mặc định là ngày hóa đơn.
   *
   * @return bool
   *   TRUE khi phiếu được tạo.
   */
  private function createDocument(string $type, InvoiceInterface $invoice, string|int|null $contact_id, string|int $warehouse_id, ?string $date = NULL): bool {
    $settings = self::DOCUMENT_SETTINGS[$type] ?? NULL;

    if ($settings === NULL) {
      return FALSE;
    }

    // Chốt chặn cuối: hóa đơn đã nhập / xuất rồi thì không ghi kho lần nữa.
    // Cờ trên hóa đơn có thể bị sửa tay nên kiểm tra thêm cả phiếu đã ghi.
    if ($this->isHandled($invoice, $settings["invoice_field"])
      || $this->warehouseService->hasTransaction($invoice, $type)) {
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
    $lines = array_filter($lines, static fn (array $item) => isset($supplies[$item["item_id"]]));
    if (empty($lines)) {
      return FALSE;
    }

    $document = $this->warehouseService->createInvoiceTransaction(
      $invoice,
      $type,
      $warehouse_id,
      $contact_id,
      $lines,
      $date
    );

    if (empty($document)) {
      return FALSE;
    }

    $invoice->set($settings["invoice_field"], 1);
    $invoice->save();

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
   * Công ty của một người dùng, gồm cả công ty kiêm nhiệm.
   *
   * @param \Drupal\user\UserInterface $user
   *   Người dùng cần đọc.
   *
   * @return array
   *   Id công ty ánh xạ sang tên.
   */
  public function userCompanies($user): array {
    $companies = $this->getCurrentPosition($user);
    $company = $user->get("field_user_company")->entity;

    if ($company) {
      $companies[$company->id()] = $company->label();
    }

    return $companies;
  }

  /**
   * Mở rộng danh sách công ty xuống các công ty con.
   *
   * @param array $company_ids
   *   Id công ty gốc.
   *
   * @return array
   *   Id công ty gốc và mọi công ty con.
   */
  public function companyScope(array $company_ids): array {
    $storage = $this->entityTypeManager->getStorage("taxonomy_term");
    $scope = [];

    foreach (array_filter($company_ids) as $company_id) {
      $scope += $this->getDescendantTids($company_id, $storage);
    }

    return $scope;
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

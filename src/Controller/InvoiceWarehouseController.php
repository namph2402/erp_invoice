<?php

namespace Drupal\erp_e_invoice\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\erp_e_invoice\InvoiceService;
use Drupal\erp_e_invoice\WarehouseService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sổ kho của hóa đơn điện tử.
 *
 * Cả ba báo cáo (phiếu nhập, phiếu xuất, tồn kho) đều đọc từ entity
 * warehouse_transaction nên luôn khớp nhau: cùng một kho, cùng một kỳ thì tổng
 * cột "nhập trong kỳ" của báo cáo tồn kho bằng đúng tổng số lượng của các phiếu
 * nhập đang liệt kê.
 */
class InvoiceWarehouseController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    protected InvoiceService $invoiceService,
    protected WarehouseService $warehouseService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get("erp_e_invoice.invoice_service"),
      $container->get("erp_e_invoice.warehouse_service"),
    );
  }

  /**
   * Danh sách phiếu nhập kho.
   */
  public function listInvoiceImport(Request $request) {
    return $this->buildTransactionList($request, WarehouseService::IMPORT);
  }

  /**
   * Danh sách phiếu xuất kho.
   */
  public function listInvoiceExport(Request $request) {
    return $this->buildTransactionList($request, WarehouseService::EXPORT);
  }

  /**
   * Báo cáo tồn đầu kỳ - nhập - xuất - tồn cuối kỳ.
   */
  public function listWarehouse(Request $request) {
    $scope = $this->getScope($request);

    $data = $scope["warehouse_id"]
      ? $this->warehouseService->report($scope["warehouse_id"], $scope["range"], $scope["declared"])
      : [];

    $units = $this->invoiceService->loadTerm("unit", "id");
    $taxs = $this->invoiceService->loadTerm("tax", "id", "field_tax_number");

    $totals = [
      "begin" => 0,
      "import" => 0,
      "export" => 0,
      "end" => 0,
    ];

    foreach ($data as $key => $row) {
      $data[$key]["unit"] = $units[$row["unit_id"]] ?? "";
      $data[$key]["tax"] = $taxs[$row["tax_id"]] ?? "";

      foreach ($totals as $column => $total) {
        $totals[$column] = $total + $row[$column];
      }
    }

    return [
      "#theme" => "list_warehouse_invoice",
      "#data" => $data,
      "#filter" => $this->filterVariables($scope, $request) + [
        "totals" => $totals,
        "differences" => $this->differenceRows($scope["balance"]),
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
   * Chi tiết các lần nhập của một vật tư trong kỳ.
   */
  public function detailInvoiceImport(Request $request, string|int $id) {
    return $this->buildDetail($request, $id, WarehouseService::IMPORT);
  }

  /**
   * Chi tiết các lần xuất của một vật tư trong kỳ.
   */
  public function detailInvoiceExport(Request $request, string|int $id) {
    return $this->buildDetail($request, $id, WarehouseService::EXPORT);
  }

  /**
   * Danh sách phiếu theo chiều nhập hoặc xuất.
   *
   * @param Request $request
   *   Yêu cầu hiện tại.
   * @param string $type
   *   "import" hoặc "export".
   */
  private function buildTransactionList(Request $request, string $type): array {
    $scope = $this->getScope($request);
    $data = [];

    $transactions = $scope["warehouse_id"]
      ? $this->warehouseService->loadTransactions($scope["warehouse_id"], $scope["range"], $type)
      : [];

    $details = $this->warehouseService->loadDetails($transactions);

    /** @var \Drupal\e_invoice\WarehouseTransactionInterface $transaction */
    foreach ($transactions as $transaction) {
      $invoice = $transaction->get("field_invoice")->entity;
      $lines = $details[$transaction->id()] ?? [];

      $data[] = [
        "id" => $transaction->id(),
        "url" => $transaction->toUrl()->toString(),
        "title" => $transaction->label(),
        "date" => substr((string) $transaction->get("field_implementation_date")->value, 0, 10),
        "warehouse" => $transaction->get("field_warehouse")->entity?->label() ?? "",
        "contact" => $transaction->get("field_contact")->entity?->label() ?? "",
        "lines" => count($lines),
        "quantity" => array_sum(array_column($lines, "quantity")),
        "total_vat" => (float) ($transaction->get("field_total_vat")->value ?? 0),
        "total_amount" => (float) ($transaction->get("field_total_amount")->value ?? 0),
        "invoice_uuid" => $invoice?->uuid() ?? "",
        "invoice_title" => $invoice ? ($invoice->get("field_invoice_no")->value ?: $invoice->label()) : "",
      ];
    }

    return [
      "#theme" => $type === WarehouseService::IMPORT ? "list_import_invoice" : "list_export_invoice",
      "#data" => $data,
      "#filter" => $this->filterVariables($scope, $request),
      "#attached" => [
        "library" => [
          "erp_e_invoice/e_invoice",
        ],
      ],
      "#cache" => ["max-age" => 0],
    ];
  }

  /**
   * Các dòng phiếu của một vật tư trong kỳ.
   *
   * @param Request $request
   *   Yêu cầu hiện tại.
   * @param string|int $id
   *   Vật tư cần xem.
   * @param string $type
   *   "import" hoặc "export".
   */
  private function buildDetail(Request $request, string|int $id, string $type): array {
    $scope = $this->getScope($request);

    if (empty($scope["warehouse_id"])) {
      throw new NotFoundHttpException();
    }

    $data = [];
    $transactions = $this->warehouseService->loadTransactions($scope["warehouse_id"], $scope["range"], $type);
    $details = $this->warehouseService->loadDetails($transactions);

    /** @var \Drupal\e_invoice\WarehouseTransactionInterface $transaction */
    foreach ($transactions as $transaction) {
      foreach ($details[$transaction->id()] ?? [] as $line) {
        if ((string) $line["item_id"] !== (string) $id) {
          continue;
        }

        $invoice = $transaction->get("field_invoice")->entity;

        $data[] = [
          "date" => substr((string) $transaction->get("field_implementation_date")->value, 0, 10),
          "quantity" => $line["quantity"],
          "price" => $line["price"],
          "amount" => $line["amount"],
          "contact" => $transaction->get("field_contact")->entity?->label() ?? "",
          "url" => $transaction->toUrl()->toString(),
          "title" => $transaction->label(),
          "invoice_uuid" => $invoice?->uuid(),
          "invoice_title" => $invoice ? ($invoice->get("field_invoice_no")->value ?: $invoice->label()) : "",
        ];
      }
    }

    return [
      "#theme" => $type === WarehouseService::IMPORT ? "detail_import_invoice" : "detail_export_invoice",
      "#data" => $data,
      "#cache" => ["max-age" => 0],
    ];
  }

  /**
   * Kho, kỳ và khoảng ngày đang xem.
   *
   * Kỳ chọn sẵn là kỳ chứa hôm nay: mở báo cáo lên là thấy ngay số liệu kỳ đang
   * làm việc mà không phải bấm gì. Khi người dùng tự nhập ngày thì khoảng ngày
   * đó thắng, và tồn đầu được tính lại từ lịch sử thay vì lấy số đã khai.
   *
   * @param Request $request
   *   Yêu cầu hiện tại.
   *
   * @return array
   *   Bộ lọc đã chuẩn hoá.
   */
  private function getScope(Request $request): array {
    /** @var \Drupal\user\Entity\User|null $user */
    $user = $this->entityTypeManager()
      ->getStorage("user")
      ->load($this->currentUser()->id());

    $companies = $user ? $this->invoiceService->userCompanies($user) : [];
    $company_id = $request->query->get("company_id") ?: array_key_first($companies);

    // Chưa gán công ty thì không thấy kho nào, thay vì thấy toàn bộ.
    $warehouses = $this->warehouseService->warehouseOptions(
      $company_id ? $this->invoiceService->companyScope([$company_id]) : [0]
    );

    $warehouse_id = $request->query->get("warehouse_id");

    if (empty($warehouse_id) || !isset($warehouses[$warehouse_id])) {
      $warehouse_id = array_key_first($warehouses);
    }

    $periods = $warehouse_id ? $this->warehouseService->periodOptions($warehouse_id) : [];
    $period_id = $request->query->get("period_id") ?: NULL;

    if (!empty($period_id) && !isset($periods[$period_id])) {
      $period_id = NULL;
    }

    $balance = NULL;

    if (!empty($period_id)) {
      $balance = $this->entityTypeManager()
        ->getStorage("warehouse_transaction")
        ->load($period_id);
    }

    // Lần đầu mở báo cáo: bám vào kỳ đang chạy để thấy ngay số liệu kỳ này.
    if (empty($balance) && $warehouse_id && !$request->query->has("period_id") && !$this->hasDateFilter($request)) {
      $balance = $this->warehouseService->findPeriod($warehouse_id, date("Y-m-d"));
    }

    $period_range = $this->warehouseService->periodRange($balance);

    // Kỳ chỉ định mốc tồn đầu, còn hai ô ngày vẫn lọc được: kế toán thu hẹp
    // trong kỳ để xem phát sinh của từng đoạn thời gian.
    $range = $this->hasDateFilter($request) || empty($balance)
      ? $this->getPeriod($request)
      : $period_range;

    return [
      "companies" => $companies,
      "company_id" => $company_id,
      "warehouses" => $warehouses,
      "warehouse_id" => $warehouse_id,
      "periods" => $periods,
      "period_ranges" => $this->periodRanges($warehouse_id),
      "period_id" => $balance?->id(),
      "balance" => $balance,
      // Số tồn đầu đã khai chỉ dùng được khi báo cáo bắt đầu đúng ngày đầu kỳ.
      // Thu hẹp khoảng ngày thì tồn đầu phải cộng thêm phát sinh từ đầu kỳ tới
      // trước ngày bắt đầu, để báo cáo tự tính lại từ lịch sử.
      "declared" => $balance && ($range["start"] ?? NULL) === ($period_range["start"] ?? NULL)
        ? $balance
        : NULL,
      "range" => $range,
    ];
  }

  /**
   * Khoảng ngày của từng kỳ, để ô chọn kỳ điền sẵn hai ô ngày.
   *
   * @param string|int|null $warehouse_id
   *   Kho đang xem.
   *
   * @return array
   *   Id kỳ ánh xạ sang mảng "start" và "end".
   */
  private function periodRanges(string|int|null $warehouse_id): array {
    $ranges = [];

    if (empty($warehouse_id)) {
      return $ranges;
    }

    foreach ($this->warehouseService->loadPeriods($warehouse_id) as $period) {
      $ranges[$period->id()] = $this->warehouseService->periodRange($period);
    }

    return $ranges;
  }

  /**
   * Biến bộ lọc dùng chung cho ba mẫu hiển thị.
   *
   * @param array $scope
   *   Bộ lọc đã chuẩn hoá.
   * @param Request $request
   *   Yêu cầu hiện tại.
   */
  private function filterVariables(array $scope, Request $request): array {
    $query = [
      "warehouse_id" => $scope["warehouse_id"],
      "start_date" => $scope["range"]["start"],
      "end_date" => $scope["range"]["end"],
    ];

    return [
      "date" => $scope["range"],
      "company_id" => $scope["company_id"],
      "option_company" => $scope["companies"],
      "warehouse_id" => $scope["warehouse_id"],
      "option_warehouse" => $scope["warehouses"],
      "period_id" => $scope["period_id"],
      "option_period" => $scope["periods"],
      "period_ranges" => $scope["period_ranges"],
      "declared" => (bool) $scope["declared"],
      // Bảng chi tiết mở trong hộp thoại phải lọc đúng kho và kỳ đang xem, nên
      // luôn gửi kèm thay vì để nó tự lấy mặc định.
      "query" => "?" . http_build_query(array_filter($query)),
      "period_url" => Url::fromRoute("erp_e_invoice.e_invoice_warehouse_period", [], [
        "query" => array_filter([
          "warehouse" => $scope["warehouse_id"],
          "start" => $scope["range"]["start"],
          "end" => $scope["range"]["end"],
          "destination" => $request->getRequestUri(),
        ]),
      ])->toString(),
      // Kỳ đang chọn có thể phải khai lại khi kỳ trước bị sửa, nên báo cáo mở
      // thẳng form cập nhật thay vì bắt đi tìm bản ghi.
      "period_edit_url" => $scope["period_id"]
        ? Url::fromRoute("erp_e_invoice.e_invoice_warehouse_period_edit", [
          "warehouse_transaction" => $scope["period_id"],
        ], [
          "query" => [
            "destination" => $request->getRequestUri(),
          ],
        ])->toString()
        : NULL,
      "warehouse_url" => Url::fromRoute("entity.warehouse_invoice.add_form", [
        "warehouse_invoice_type" => WarehouseService::WAREHOUSE_BUNDLE,
      ], [
        "query" => [
          "destination" => $request->getRequestUri(),
        ],
      ])->toString(),
      "export" => $this->exportInfo($scope),
      "page_size" => InvoiceService::DEFAULT_PAGE_SIZE,
      "option_page_size" => InvoiceService::PAGE_SIZES,
      "destination" => $request->getRequestUri(),
      "current_user" => $this->currentUser()->getAccountName(),
    ];
  }

  /**
   * Thông tin kỳ số liệu đi kèm file Excel.
   *
   * Người nhận file không thấy được bộ lọc trên màn hình, nên kỳ phải nằm trong
   * chính file và trong tên file.
   *
   * @param array $scope
   *   Bộ lọc đã chuẩn hoá.
   *
   * @return array
   *   "period" là dòng chữ đặt trên bảng, "range" là phần thêm vào tên file,
   *   "warehouse" là tên kho đang xem.
   */
  private function exportInfo(array $scope): array {
    $start = $scope["range"]["start"] ?? NULL;
    $end = $scope["range"]["end"] ?? NULL;

    $info = [
      "warehouse" => $scope["warehouses"][$scope["warehouse_id"]] ?? "",
      "period" => (string) $this->t("Full date"),
      "range" => "",
    ];

    if (empty($start) && empty($end)) {
      return $info;
    }

    $info["period"] = (string) $this->t("From @start to @end", [
      "@start" => $start ? date("d/m/Y", strtotime($start)) : "...",
      "@end" => $end ? date("d/m/Y", strtotime($end)) : "...",
    ]);

    $info["range"] = ($start ? date("Ymd", strtotime($start)) : "")
      . "-" . ($end ? date("Ymd", strtotime($end)) : "");

    return $info;
  }

  /**
   * Vật tư có tồn đầu kỳ lệch so với tồn cuối kỳ trước.
   *
   * @param \Drupal\e_invoice\WarehouseTransactionInterface|null $balance
   *   Bản ghi tồn đầu kỳ đang xem.
   */
  private function differenceRows($balance): array {
    if (empty($balance)) {
      return [];
    }

    $differences = $this->warehouseService->periodDifferences($balance);

    if (empty($differences)) {
      return [];
    }

    $supplies = $this->entityTypeManager()
      ->getStorage("supplies")
      ->loadMultiple(array_keys($differences));

    $rows = [];

    foreach ($differences as $supply_id => $difference) {
      $rows[] = $difference + [
        "name" => ($supplies[$supply_id] ?? NULL)?->label() ?? "#" . $supply_id,
      ];
    }

    return $rows;
  }

  /**
   * Người dùng có tự chọn khoảng ngày thay vì chọn kỳ không.
   */
  private function hasDateFilter(Request $request): bool {
    return (bool) ($request->query->get("start_date")
      || $request->query->get("end_date")
      || $request->query->get("search_date"));
  }

  /**
   * Kỳ báo cáo lấy từ bộ lọc trên URL.
   *
   * Mặc định là từ đầu tháng đến hôm nay. Các nút chọn nhanh gửi lên
   * "search_date", trong đó "full_date" nghĩa là bỏ giới hạn ngày.
   *
   * @return array
   *   Mảng "start" và "end" dạng Y-m-d, NULL khi không giới hạn.
   */
  private function getPeriod(Request $request): array {
    // Ô ngày gửi lên rỗng nghĩa là người dùng chủ động bỏ giới hạn, chỉ khi
    // chưa gửi ô nào mới lấy mặc định tháng này.
    $has_date_filter = $request->query->has("start_date") || $request->query->has("end_date");

    $start_date = $request->query->get("start_date")
      ?: ($has_date_filter ? NULL : date("Y-m-01"));

    $end_date = $request->query->get("end_date")
      ?: ($has_date_filter ? NULL : date("Y-m-d"));

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

    return [
      "start" => $start_date ? date("Y-m-d", strtotime($start_date)) : NULL,
      "end" => $end_date ? date("Y-m-d", strtotime($end_date)) : NULL,
    ];
  }

}

<?php

namespace Drupal\erp_e_invoice;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\e_invoice\InvoiceInterface;

/**
 * Ghi và đọc kho hàng của hóa đơn điện tử.
 *
 * Kho là entity "warehouse_invoice" (mỗi công ty một hoặc nhiều kho). Mọi biến
 * động kho nằm ở entity "warehouse_transaction" với hai nhóm:
 *
 * - warehouse_opening_balance: tồn đầu kỳ, mỗi bản ghi khai báo một kỳ
 *   [ngày bắt đầu, ngày kết thúc] và số lượng từng vật tư ở đầu kỳ đó.
 * - warehouse_history: một phiếu nhập hoặc phiếu xuất, gắn với hóa đơn nguồn
 *   và ngày thực hiện.
 *
 * Tồn kho không được lưu sẵn ở đâu cả: tồn tại một thời điểm luôn được tính
 * bằng tồn đầu kỳ gần nhất cộng trừ các phiếu phát sinh sau đó. Nhờ vậy sửa
 * hay xoá một phiếu là báo cáo tự đúng lại, không phải cộng trừ ngược.
 */
class WarehouseService {

  use StringTranslationTrait;

  /**
   * Chiều nhập hàng.
   */
  public const IMPORT = "import";

  /**
   * Chiều xuất hàng.
   */
  public const EXPORT = "export";

  /**
   * Nhóm kho hàng.
   */
  public const WAREHOUSE_BUNDLE = "warehouse_custorm";

  /**
   * Nhóm phiếu nhập / xuất.
   */
  public const HISTORY_BUNDLE = "warehouse_history";

  /**
   * Nhóm tồn đầu kỳ.
   */
  public const OPENING_BUNDLE = "warehouse_opening_balance";

  /**
   * Nhóm paragraph dòng chi tiết dùng chung cho cả hai nhóm phiếu.
   */
  public const DETAIL_BUNDLE = "warehouse_invoice_list";

  /**
   * Bộ từ vựng đơn vị tính.
   */
  public const UNIT_VOCABULARY = "unit";

  /**
   * Constructs a WarehouseService object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param Connection $connection
   *   The database connection.
   * @param LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $connection,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Danh sách kỳ đã nạp, theo kho.
   *
   * Một lượt dựng báo cáo hỏi kỳ của cùng một kho nhiều lần (tìm kỳ chứa ngày,
   * kỳ liền trước, kỳ đã khoá chưa), giữ lại để khỏi truy vấn lặp.
   */
  private array $periods = [];

  /**
   * Đơn vị tính đã nạp, tên viết thường ánh xạ sang id term.
   *
   * Hóa đơn ghi đơn vị bằng chữ ("cái", "bộ"...) nên mỗi lượt ghi phiếu phải
   * tra ngược lại term; danh mục ngắn, nạp một lần cho cả lượt.
   */
  private ?array $units = NULL;

  /* --------------------------------------------------------------------- */
  /* Kho hàng                                                              */
  /* --------------------------------------------------------------------- */

  /**
   * Kho hàng đang dùng, lọc theo công ty khi có.
   *
   * @param array $company_ids
   *   Danh sách term công ty; để trống thì lấy mọi kho.
   *
   * @return array
   *   Kho hàng theo id.
   */
  public function loadWarehouses(array $company_ids = []): array {
    $storage = $this->entityTypeManager->getStorage("warehouse_invoice");

    $query = $storage->getQuery()
      ->condition("bundle", self::WAREHOUSE_BUNDLE)
      ->condition("status", 1)
      ->sort("label", "ASC")
      ->accessCheck(TRUE);

    $company_ids = array_values(array_filter($company_ids));

    if (!empty($company_ids)) {
      $query->condition("field_company", $company_ids, "IN");
    }

    return $storage->loadMultiple($query->execute());
  }

  /**
   * Danh sách kho cho ô chọn.
   *
   * @param array $company_ids
   *   Danh sách term công ty; để trống thì lấy mọi kho.
   *
   * @return array
   *   Id kho ánh xạ sang nhãn kèm mã kho.
   */
  public function warehouseOptions(array $company_ids = []): array {
    $options = [];

    /** @var \Drupal\e_invoice\WarehouseInvoiceInterface $warehouse */
    foreach ($this->loadWarehouses($company_ids) as $warehouse) {
      $code = (string) ($warehouse->get("field_code")->value ?? "");

      $options[$warehouse->id()] = $code !== ""
        ? $warehouse->label() . " (" . $code . ")"
        : $warehouse->label();
    }

    return $options;
  }

  /* --------------------------------------------------------------------- */
  /* Kỳ kho (tồn đầu kỳ)                                                   */
  /* --------------------------------------------------------------------- */

  /**
   * Các kỳ đã khai báo của một kho, kỳ mới nhất đứng đầu.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   *
   * @return array
   *   Bản ghi tồn đầu kỳ theo id.
   */
  public function loadPeriods(string|int $warehouse_id): array {
    if (empty($warehouse_id)) {
      return [];
    }

    if (isset($this->periods[$warehouse_id])) {
      return $this->periods[$warehouse_id];
    }

    $storage = $this->entityTypeManager->getStorage("warehouse_transaction");

    $ids = $storage->getQuery()
      ->condition("bundle", self::OPENING_BUNDLE)
      ->condition("field_warehouse", $warehouse_id)
      ->sort("field_start_date", "DESC")
      ->accessCheck(TRUE)
      ->execute();

    return $this->periods[$warehouse_id] = $storage->loadMultiple($ids);
  }

  /**
   * Danh sách kỳ cho ô chọn.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   *
   * @return array
   *   Id kỳ ánh xạ sang nhãn kèm khoảng ngày.
   */
  public function periodOptions(string|int $warehouse_id): array {
    $options = [];

    foreach ($this->loadPeriods($warehouse_id) as $period) {
      $options[$period->id()] = $this->periodLabel($period);
    }

    return $options;
  }

  /**
   * Nhãn hiển thị của một kỳ.
   *
   * @param \Drupal\e_invoice\WarehouseTransactionInterface $period
   *   Bản ghi tồn đầu kỳ.
   */
  public function periodLabel($period): string {
    $range = $this->periodRange($period);

    $label = $period->label();
    $start = $range["start"] ? date("d/m/Y", strtotime($range["start"])) : "...";
    $end = $range["end"] ? date("d/m/Y", strtotime($range["end"])) : "...";

    return $label . " (" . $start . " - " . $end . ")";
  }

  /**
   * Khoảng ngày của một kỳ.
   *
   * @param \Drupal\e_invoice\WarehouseTransactionInterface|null $period
   *   Bản ghi tồn đầu kỳ.
   *
   * @return array
   *   Mảng "start" và "end" dạng Y-m-d, NULL khi kỳ để mở đầu này.
   */
  public function periodRange($period): array {
    if (empty($period)) {
      return ["start" => NULL, "end" => NULL];
    }

    $start = substr((string) $period->get("field_start_date")->value, 0, 10);
    $end = substr((string) $period->get("field_end_date")->value, 0, 10);

    return [
      "start" => $start !== "" ? $start : NULL,
      "end" => $end !== "" ? $end : NULL,
    ];
  }

  /**
   * Kỳ chứa một ngày cụ thể.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param string $date
   *   Ngày cần tra, dạng Y-m-d.
   *
   * @return \Drupal\e_invoice\WarehouseTransactionInterface|null
   *   Bản ghi tồn đầu kỳ, NULL khi ngày không thuộc kỳ nào.
   */
  public function findPeriod(string|int $warehouse_id, string $date) {
    foreach ($this->loadPeriods($warehouse_id) as $period) {
      $range = $this->periodRange($period);

      if (!empty($range["start"]) && $date < $range["start"]) {
        continue;
      }

      if (!empty($range["end"]) && $date > $range["end"]) {
        continue;
      }

      return $period;
    }

    return NULL;
  }

  /**
   * Kỳ liền trước một ngày, dùng khi ngày đó chưa được kỳ nào phủ.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param string $date
   *   Ngày cần tra, dạng Y-m-d.
   *
   * @return \Drupal\e_invoice\WarehouseTransactionInterface|null
   *   Kỳ gần nhất bắt đầu trước hoặc đúng ngày đó.
   */
  public function findPreviousPeriod(string|int $warehouse_id, string $date) {
    // loadPeriods() đã sắp xếp kỳ mới nhất trước nên bản ghi đầu tiên thoả
    // điều kiện chính là kỳ gần nhất.
    foreach ($this->loadPeriods($warehouse_id) as $period) {
      $range = $this->periodRange($period);

      if (empty($range["start"]) || $range["start"] <= $date) {
        return $period;
      }
    }

    return NULL;
  }

  /**
   * Kỳ sớm nhất của một kho.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   *
   * @return \Drupal\e_invoice\WarehouseTransactionInterface|null
   *   Kỳ có ngày bắt đầu sớm nhất, NULL khi kho chưa khai kỳ nào.
   */
  public function findEarliestPeriod(string|int $warehouse_id) {
    $periods = $this->loadPeriods($warehouse_id);

    if (empty($periods)) {
      return NULL;
    }

    // loadPeriods() sắp kỳ mới nhất trước nên kỳ sớm nhất nằm ở cuối.
    return $periods[array_key_last($periods)];
  }

  /**
   * Kỳ đã bị khoá chưa.
   *
   * Kỳ được coi là khoá khi đã có kỳ sau nó: tồn đầu kỳ sau được kết chuyển từ
   * tồn cuối kỳ này nên ghi thêm phiếu vào kỳ này sẽ làm số liệu kỳ sau sai.
   *
   * @param \Drupal\e_invoice\WarehouseTransactionInterface $period
   *   Bản ghi tồn đầu kỳ.
   */
  public function isPeriodClosed($period): bool {
    $range = $this->periodRange($period);

    if (empty($range["start"])) {
      return FALSE;
    }

    $ids = $this->entityTypeManager
      ->getStorage("warehouse_transaction")
      ->getQuery()
      ->condition("bundle", self::OPENING_BUNDLE)
      ->condition("field_warehouse", $period->get("field_warehouse")->target_id)
      ->condition("field_start_date", $range["start"], ">")
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    return !empty($ids);
  }

  /**
   * Kỳ mới có chồng lấn kỳ đã khai báo không.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param string $start
   *   Ngày bắt đầu kỳ mới.
   * @param string|null $end
   *   Ngày kết thúc kỳ mới, NULL nghĩa là chưa chốt.
   * @param string|int|null $exclude_id
   *   Bỏ qua một kỳ khi đang sửa chính kỳ đó.
   *
   * @return \Drupal\e_invoice\WarehouseTransactionInterface|null
   *   Kỳ bị chồng lấn đầu tiên tìm được.
   */
  public function findOverlappingPeriod(string|int $warehouse_id, string $start, ?string $end, string|int|null $exclude_id = NULL) {
    foreach ($this->loadPeriods($warehouse_id) as $period) {
      if ($exclude_id !== NULL && (string) $period->id() === (string) $exclude_id) {
        continue;
      }

      $range = $this->periodRange($period);

      // Kỳ để trống ngày kết thúc kéo dài vô hạn về sau, kỳ để trống ngày bắt
      // đầu kéo dài vô hạn về trước.
      if (!empty($end) && !empty($range["start"]) && $end < $range["start"]) {
        continue;
      }

      if (!empty($range["end"]) && $start > $range["end"]) {
        continue;
      }

      return $period;
    }

    return NULL;
  }

  /* --------------------------------------------------------------------- */
  /* Đọc số liệu                                                           */
  /* --------------------------------------------------------------------- */

  /**
   * Số lượng từng vật tư ghi trên một bản ghi tồn đầu kỳ.
   *
   * @param \Drupal\e_invoice\WarehouseTransactionInterface|null $period
   *   Bản ghi tồn đầu kỳ.
   *
   * @return array
   *   Id vật tư ánh xạ sang ["quantity", "price", "unit_id"].
   */
  public function openingQuantities($period): array {
    $opening = [];

    if (empty($period)) {
      return $opening;
    }

    foreach ($this->loadDetails([$period]) as $lines) {
      foreach ($lines as $line) {
        $supply_id = $line["item_id"];

        if (empty($supply_id)) {
          continue;
        }

        if (!isset($opening[$supply_id])) {
          $opening[$supply_id] = [
            "quantity" => 0,
            "price" => $line["price"],
            "unit_id" => $line["unit_id"] ?? NULL,
          ];
        }

        // Dòng gặp đầu tiên có thể là dòng cũ chưa ghi đơn vị, lấy tiếp của
        // dòng sau thay vì để trống cả vật tư.
        if (empty($opening[$supply_id]["unit_id"]) && !empty($line["unit_id"])) {
          $opening[$supply_id]["unit_id"] = $line["unit_id"];
        }

        $opening[$supply_id]["quantity"] += $line["quantity"];
      }
    }

    return $opening;
  }

  /**
   * Phiếu nhập / xuất của một kho trong khoảng ngày.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param array $period
   *   Mảng "start" và "end" dạng Y-m-d, để NULL là không giới hạn.
   * @param string|null $type
   *   Lọc theo chiều "import" hoặc "export", NULL là lấy cả hai.
   *
   * @return array
   *   Phiếu theo id, mới nhất trước.
   */
  public function loadTransactions(string|int $warehouse_id, array $period = [], ?string $type = NULL): array {
    if (empty($warehouse_id)) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage("warehouse_transaction");

    $query = $storage->getQuery()
      ->condition("bundle", self::HISTORY_BUNDLE)
      ->condition("field_warehouse", $warehouse_id)
      ->sort("field_implementation_date", "DESC")
      ->sort("id", "DESC")
      ->accessCheck(TRUE);

    if ($type !== NULL) {
      $query->condition("field_type", $type);
    }

    if (!empty($period["start"])) {
      $query->condition("field_implementation_date", $period["start"], ">=");
    }

    if (!empty($period["end"])) {
      $query->condition("field_implementation_date", $period["end"], "<=");
    }

    return $storage->loadMultiple($query->execute());
  }

  /**
   * Dòng chi tiết của nhiều phiếu, nạp một lượt.
   *
   * Paragraph tạo bằng mã lệnh không ghi lại quan hệ cha nên không truy vấn
   * được theo parent_id; phải đi từ giá trị trường của phiếu.
   *
   * @param array $transactions
   *   Danh sách phiếu (nhập / xuất hoặc tồn đầu kỳ).
   *
   * @return array
   *   Id phiếu ánh xạ sang danh sách dòng đã chuẩn hoá.
   */
  public function loadDetails(array $transactions): array {
    $rows = [];
    $owners = [];

    foreach ($transactions as $transaction) {
      $rows[$transaction->id()] = [];

      if (!$transaction->hasField("field_detail")) {
        continue;
      }

      foreach ($transaction->get("field_detail")->getValue() as $item) {
        if (!empty($item["target_revision_id"])) {
          $owners[$item["target_revision_id"]][] = $transaction->id();
        }
      }
    }

    if (empty($owners)) {
      return $rows;
    }

    $paragraphs = $this->entityTypeManager
      ->getStorage("paragraph")
      ->loadMultipleRevisions(array_keys($owners));

    foreach ($owners as $revision_id => $transaction_ids) {
      /** @var \Drupal\paragraphs\Entity\Paragraph|null $paragraph */
      $paragraph = $paragraphs[$revision_id] ?? NULL;

      if (!$paragraph || $paragraph->bundle() !== self::DETAIL_BUNDLE) {
        continue;
      }

      $quantity = (float) ($paragraph->get("field_invoice_quantity")->value ?? 0);
      $price = (float) ($paragraph->get("field_invoice_price")->value ?? 0);
      $discount = (float) ($paragraph->get("field_invoice_discount")->value ?? 0);
      $vat_rate = (float) ($paragraph->get("field_invoice_vat")->value ?? 0);

      // Thành tiền đã lưu là số của chứng từ gốc, tính lại chỉ để đỡ dòng cũ
      // ghi trước khi có trường này.
      $amount = $paragraph->hasField("field_invoice_amount") && !$paragraph->get("field_invoice_amount")->isEmpty()
        ? (float) $paragraph->get("field_invoice_amount")->value
        : $this->lineAmount([
          "quantity" => $quantity,
          "price" => $price,
          "discount" => $discount,
        ]);

      // Đơn vị tính ghi ngay trên dòng phiếu: vật tư đổi đơn vị mua về sau thì
      // phiếu cũ vẫn phải đọc lại đúng đơn vị lúc ghi.
      $unit = $paragraph->hasField("field_invoice_unit")
        ? $paragraph->get("field_invoice_unit")->entity
        : NULL;

      $line = [
        "item_id" => $paragraph->get("field_invoice_item")->target_id,
        "item" => $paragraph->get("field_invoice_item")->entity,
        "unit_id" => $unit?->id(),
        "unit" => $unit?->label() ?? "",
        "quantity" => $quantity,
        "price" => $price,
        "discount" => $discount,
        "vat_rate" => $vat_rate,
        "amount" => $amount,
        "vat_amount" => $amount * $vat_rate / 100,
      ];

      foreach ($transaction_ids as $transaction_id) {
        $rows[$transaction_id][] = $line;
      }
    }

    return $rows;
  }

  /**
   * Tổng số lượng nhập / xuất theo vật tư trong khoảng ngày.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param array $period
   *   Mảng "start" và "end" dạng Y-m-d.
   *
   * @return array
   *   Id vật tư ánh xạ sang ["import", "export", "price", "unit_id"].
   */
  public function movements(string|int $warehouse_id, array $period = []): array {
    $totals = [];
    $transactions = $this->loadTransactions($warehouse_id, $period);
    $details = $this->loadDetails($transactions);

    foreach ($transactions as $transaction) {
      $type = (string) $transaction->get("field_type")->value;
      $type = $type === self::EXPORT ? self::EXPORT : self::IMPORT;

      // Mốc thời gian của phiếu, dùng để chọn lần nhập gần nhất. Phải so tay
      // vì loadMultiple() trả phiếu theo id chứ không giữ thứ tự ngày của truy
      // vấn; cùng ngày thì phiếu ghi sau được coi là mới hơn.
      $stamp = [
        substr((string) $transaction->get("field_implementation_date")->value, 0, 10),
        (int) $transaction->id(),
      ];

      foreach ($details[$transaction->id()] ?? [] as $line) {
        $supply_id = $line["item_id"];

        if (empty($supply_id)) {
          continue;
        }

        if (!isset($totals[$supply_id])) {
          $totals[$supply_id] = [
            self::IMPORT => 0,
            self::EXPORT => 0,
            "price" => $line["price"],
            "unit_id" => $line["unit_id"] ?? NULL,
            // Chưa có lần nhập nào định giá: giá trên mới chỉ là giá tạm của
            // dòng đầu gặp, kể cả khi đó là phiếu xuất.
            "price_stamp" => NULL,
          ];
        }

        $totals[$supply_id][$type] += $line["quantity"];

        if (empty($totals[$supply_id]["unit_id"]) && !empty($line["unit_id"])) {
          $totals[$supply_id]["unit_id"] = $line["unit_id"];
        }

        // Giá lấy theo lần nhập gần nhất để báo cáo có giá trị tham chiếu.
        if ($type === self::IMPORT
          && $line["price"] > 0
          && ($totals[$supply_id]["price_stamp"] === NULL
            || $stamp >= $totals[$supply_id]["price_stamp"])
        ) {
          $totals[$supply_id]["price"] = $line["price"];
          $totals[$supply_id]["price_stamp"] = $stamp;

          // Đơn vị đi theo giá: cùng một vật tư nhập bằng hai đơn vị thì báo
          // cáo phải đọc theo lần nhập gần nhất, không phải lần đầu gặp.
          if (!empty($line["unit_id"])) {
            $totals[$supply_id]["unit_id"] = $line["unit_id"];
          }
        }
      }
    }

    foreach ($totals as &$total) {
      unset($total["price_stamp"]);
    }

    return $totals;
  }

  /**
   * Báo cáo tồn đầu kỳ - nhập - xuất - tồn cuối kỳ.
   *
   * Kỳ báo cáo lấy theo bản ghi tồn đầu kỳ khi có, còn không thì theo khoảng
   * ngày người dùng lọc. Tồn đầu của khoảng ngày tự do được tính lại từ kỳ gần
   * nhất cộng trừ phát sinh cho tới trước ngày bắt đầu.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param array $period
   *   Mảng "start" và "end" dạng Y-m-d.
   * @param \Drupal\e_invoice\WarehouseTransactionInterface|null $balance
   *   Bản ghi tồn đầu kỳ tương ứng, NULL khi kho chưa khai báo kỳ nào.
   *
   * @return array
   *   Danh sách dòng báo cáo, đã sắp theo tên vật tư.
   */
  public function report(string|int $warehouse_id, array $period, $balance = NULL): array {
    $opening = $balance !== NULL
      ? $this->openingQuantities($balance)
      : $this->quantitiesBefore($warehouse_id, $period["start"] ?? NULL);

    $movements = $this->movements($warehouse_id, $period);

    $supply_ids = array_unique(array_merge(array_keys($opening), array_keys($movements)));

    if (empty($supply_ids)) {
      return [];
    }

    $supplies = $this->entityTypeManager
      ->getStorage("supplies")
      ->loadMultiple($supply_ids);

    $rows = [];

    foreach ($supply_ids as $supply_id) {
      /** @var \Drupal\eck\Entity\EckEntity|null $supply */
      $supply = $supplies[$supply_id] ?? NULL;

      // Vật tư bị xoá sau khi ghi phiếu vẫn phải hiện, nếu không tổng tồn kho
      // của báo cáo sẽ lệch so với các phiếu đã ghi.
      $begin = (float) ($opening[$supply_id]["quantity"] ?? 0);
      $import = (float) ($movements[$supply_id][self::IMPORT] ?? 0);
      $export = (float) ($movements[$supply_id][self::EXPORT] ?? 0);

      $rows[] = [
        "id" => $supply_id,
        "uuid" => $supply?->uuid(),
        "name" => $supply?->label() ?? $this->t("Deleted product") . " #" . $supply_id,
        "code" => $supply && $supply->hasField("field_sup_code")
          ? $supply->get("field_sup_code")->value
          : "",
        // Đơn vị của phiếu thắng đơn vị mua của vật tư: báo cáo phải đọc đúng
        // thứ đã ghi vào sổ, đơn vị của vật tư chỉ là mặc định cho dòng cũ.
        "unit_id" => $movements[$supply_id]["unit_id"]
          ?? $opening[$supply_id]["unit_id"]
          ?? ($supply && $supply->hasField("field_sup_unit_buy")
            ? $supply->get("field_sup_unit_buy")->target_id
            : NULL),
        "tax_id" => $supply && $supply->hasField("field_sup_tax")
          ? $supply->get("field_sup_tax")->target_id
          : NULL,
        "price" => (float) ($movements[$supply_id]["price"] ?? $opening[$supply_id]["price"] ?? 0),
        "begin" => $begin,
        "import" => $import,
        "export" => $export,
        "end" => $begin + $import - $export,
      ];
    }

    usort($rows, static fn (array $a, array $b) => strcasecmp((string) $a["name"], (string) $b["name"]));

    return $rows;
  }

  /**
   * Tồn kho của mọi vật tư ngay trước một ngày.
   *
   * Dùng để kết chuyển tồn đầu kỳ mới: lấy tồn đầu của kỳ gần nhất rồi cộng trừ
   * mọi phiếu từ đầu kỳ đó tới hết ngày liền trước.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param string|null $date
   *   Ngày mốc dạng Y-m-d; NULL nghĩa là lấy toàn bộ lịch sử.
   *
   * @return array
   *   Id vật tư ánh xạ sang ["quantity", "price", "unit_id"].
   */
  public function quantitiesBefore(string|int $warehouse_id, ?string $date): array {
    if (empty($date)) {
      // Không giới hạn ngày bắt đầu thì tồn đầu là số khai ở kỳ sớm nhất: đó là
      // phần tồn mang từ ngoài hệ thống vào, không nằm trong phiếu nào. Bỏ qua
      // nó thì tồn cuối của báo cáo "toàn bộ" lại nhỏ hơn báo cáo theo kỳ, dù
      // hai bên cùng chốt tới hôm nay.
      return $this->openingQuantities($this->findEarliestPeriod($warehouse_id));
    }

    $limit = date("Y-m-d", strtotime($date . " -1 day"));

    return $this->quantitiesAt($warehouse_id, $limit);
  }

  /**
   * Tồn kho của mọi vật tư tính đến hết một ngày.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param string $date
   *   Ngày chốt dạng Y-m-d.
   *
   * @return array
   *   Id vật tư ánh xạ sang ["quantity", "price", "unit_id"].
   */
  public function quantitiesAt(string|int $warehouse_id, string $date): array {
    $balance = $this->findPeriod($warehouse_id, $date)
      ?: $this->findPreviousPeriod($warehouse_id, $date);

    $opening = $this->openingQuantities($balance);
    $range = $this->periodRange($balance);

    $movements = $this->movements($warehouse_id, [
      // Tồn đầu kỳ đã gộp sẵn mọi phát sinh trước kỳ nên chỉ cộng từ đầu kỳ.
      "start" => $range["start"],
      "end" => $date,
    ]);

    $quantities = [];

    foreach (array_unique(array_merge(array_keys($opening), array_keys($movements))) as $supply_id) {
      $quantity = (float) ($opening[$supply_id]["quantity"] ?? 0)
        + (float) ($movements[$supply_id][self::IMPORT] ?? 0)
        - (float) ($movements[$supply_id][self::EXPORT] ?? 0);

      $quantities[$supply_id] = [
        "quantity" => $quantity,
        "price" => (float) ($movements[$supply_id]["price"] ?? $opening[$supply_id]["price"] ?? 0),
        "unit_id" => $movements[$supply_id]["unit_id"] ?? $opening[$supply_id]["unit_id"] ?? NULL,
      ];
    }

    return $quantities;
  }

  /**
   * Tồn cuối kỳ của một bản ghi tồn đầu kỳ.
   *
   * @param \Drupal\e_invoice\WarehouseTransactionInterface $balance
   *   Bản ghi tồn đầu kỳ.
   *
   * @return array
   *   Id vật tư ánh xạ sang ["quantity", "price", "unit_id"].
   */
  public function closingQuantities($balance): array {
    $range = $this->periodRange($balance);
    $warehouse_id = $balance->get("field_warehouse")->target_id;

    $opening = $this->openingQuantities($balance);
    $movements = $this->movements($warehouse_id, $range);

    $quantities = [];

    foreach (array_unique(array_merge(array_keys($opening), array_keys($movements))) as $supply_id) {
      $quantities[$supply_id] = [
        "quantity" => (float) ($opening[$supply_id]["quantity"] ?? 0)
          + (float) ($movements[$supply_id][self::IMPORT] ?? 0)
          - (float) ($movements[$supply_id][self::EXPORT] ?? 0),
        "price" => (float) ($movements[$supply_id]["price"] ?? $opening[$supply_id]["price"] ?? 0),
        "unit_id" => $movements[$supply_id]["unit_id"] ?? $opening[$supply_id]["unit_id"] ?? NULL,
      ];
    }

    return $quantities;
  }

  /**
   * Chênh lệch giữa tồn đầu kỳ đã khai và tồn cuối của kỳ liền trước.
   *
   * Kỳ trước bị sửa sau khi kỳ sau đã kết chuyển là lỗi hay gặp nhất của sổ
   * kho, ở đây chỉ ra ngay thay vì để kế toán tự dò.
   *
   * @param \Drupal\e_invoice\WarehouseTransactionInterface $balance
   *   Bản ghi tồn đầu kỳ cần đối chiếu.
   *
   * @return array
   *   Danh sách vật tư lệch: id vật tư ánh xạ sang ["declared", "expected"].
   */
  public function periodDifferences($balance): array {
    $range = $this->periodRange($balance);

    if (empty($range["start"])) {
      return [];
    }

    $warehouse_id = $balance->get("field_warehouse")->target_id;
    $previous = $this->findPreviousPeriod(
      $warehouse_id,
      date("Y-m-d", strtotime($range["start"] . " -1 day"))
    );

    // Kỳ đầu tiên của kho không có gì để đối chiếu.
    if (empty($previous) || (string) $previous->id() === (string) $balance->id()) {
      return [];
    }

    $declared = $this->openingQuantities($balance);
    $expected = $this->closingQuantities($previous);

    $differences = [];

    foreach (array_unique(array_merge(array_keys($declared), array_keys($expected))) as $supply_id) {
      $left = round((float) ($declared[$supply_id]["quantity"] ?? 0), 2);
      $right = round((float) ($expected[$supply_id]["quantity"] ?? 0), 2);

      if ($left === $right) {
        continue;
      }

      $differences[$supply_id] = [
        "declared" => $left,
        "expected" => $right,
      ];
    }

    return $differences;
  }

  /* --------------------------------------------------------------------- */
  /* Ghi số liệu                                                           */
  /* --------------------------------------------------------------------- */

  /**
   * Tạo bản ghi tồn đầu kỳ.
   *
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param string $start
   *   Ngày bắt đầu kỳ.
   * @param string|null $end
   *   Ngày kết thúc kỳ.
   * @param array $lines
   *   Danh sách dòng ["item_id", "quantity", "price", "unit_id"].
   * @param string|null $label
   *   Tên kỳ, để trống thì tự đặt theo khoảng ngày.
   *
   * @return \Drupal\e_invoice\WarehouseTransactionInterface|null
   *   Bản ghi vừa tạo, NULL khi lỗi.
   */
  public function createOpeningBalance(string|int $warehouse_id, string $start, ?string $end, array $lines, ?string $label = NULL) {
    unset($this->periods[$warehouse_id]);

    $values = [
      "bundle" => self::OPENING_BUNDLE,
      "label" => $label ?: $this->defaultPeriodLabel($start, $end),
      "field_warehouse" => $warehouse_id,
      "field_start_date" => $start,
      "field_end_date" => $end ?: NULL,
    ];

    return $this->saveTransaction($values, $lines);
  }

  /**
   * Cập nhật lại một bản ghi tồn đầu kỳ đã có.
   *
   * Dùng khi kỳ trước bị sửa: khai lại tồn đầu kỳ này theo tồn cuối kỳ trước
   * thay vì xoá bản ghi rồi tạo lại, để mọi thứ đang trỏ vào kỳ vẫn còn nguyên.
   *
   * @param \Drupal\e_invoice\WarehouseTransactionInterface $balance
   *   Bản ghi tồn đầu kỳ cần cập nhật.
   * @param string $start
   *   Ngày bắt đầu kỳ.
   * @param string|null $end
   *   Ngày kết thúc kỳ.
   * @param array $lines
   *   Danh sách dòng ["item_id", "quantity", "price", "unit_id"].
   * @param string|null $label
   *   Tên kỳ, để trống thì tự đặt theo khoảng ngày.
   *
   * @return \Drupal\e_invoice\WarehouseTransactionInterface|null
   *   Bản ghi vừa cập nhật, NULL khi lỗi.
   */
  public function updateOpeningBalance($balance, string $start, ?string $end, array $lines, ?string $label = NULL) {
    if (empty($balance) || $balance->bundle() !== self::OPENING_BUNDLE) {
      return NULL;
    }

    unset($this->periods[$balance->get("field_warehouse")->target_id]);

    $transaction = $this->connection->startTransaction();

    try {
      $obsolete = array_column($balance->get("field_detail")->getValue(), "target_id");

      $balance->set("label", $label ?: $this->defaultPeriodLabel($start, $end));
      $balance->set("field_start_date", $start);
      $balance->set("field_end_date", $end ?: NULL);
      $balance->set("field_detail", $this->buildDetail($balance, $lines));
      $balance->save();

      $this->deleteParagraphs($obsolete);

      return $balance;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->loggerFactory->get("erp_e_invoice")->error($e->getMessage());

      return NULL;
    }
  }

  /**
   * Tạo phiếu nhập / xuất từ hóa đơn.
   *
   * @param InvoiceInterface $invoice
   *   Hóa đơn nguồn.
   * @param string $type
   *   "import" hoặc "export".
   * @param string|int $warehouse_id
   *   Kho hàng.
   * @param string|int|null $contact_id
   *   Nhà cung cấp (nhập) hoặc khách hàng (xuất).
   * @param array $lines
   *   Danh sách dòng hàng đã đối chiếu, lấy từ trường dòng hóa đơn.
   * @param string|null $date
   *   Ngày ghi sổ, mặc định là ngày hóa đơn.
   *
   * @return \Drupal\e_invoice\WarehouseTransactionInterface|null
   *   Phiếu vừa tạo, NULL khi lỗi.
   */
  public function createInvoiceTransaction(InvoiceInterface $invoice, string $type, string|int $warehouse_id, string|int|null $contact_id, array $lines, ?string $date = NULL) {
    $type = $type === self::EXPORT ? self::EXPORT : self::IMPORT;
    $number = (string) ($invoice->get("field_invoice_no")->value ?? "");
    $date = $date ?: $this->invoiceDate($invoice);

    $detail = [];
    $total_amount = 0;
    $total_vat = 0;

    foreach ($lines as $line) {
      $detail[] = [
        "item_id" => $line["item_id"],
        // Hóa đơn chỉ ghi tên đơn vị, buildDetail() lo phần tra ra term.
        "unit" => (string) ($line["item_unit"] ?? ""),
        "quantity" => (float) ($line["item_quantity"] ?? 0),
        "price" => (float) ($line["item_price"] ?? 0),
        "discount" => (float) ($line["item_discount_amount"] ?? 0),
        "vat_rate" => (float) ($line["item_vat_rate"] ?? 0),
        // Chép thẳng tiền hàng của hóa đơn thay vì nhân lại: hóa đơn có thể làm
        // tròn khác, ghi số của mình vào sẽ lệch với chứng từ gốc.
        "amount" => (float) ($line["item_amount_without_vat"] ?? 0),
      ];

      $total_amount += (float) ($line["item_total_amount"] ?? 0);
      $total_vat += (float) ($line["item_vat_amount"] ?? 0);
    }

    $values = [
      "bundle" => self::HISTORY_BUNDLE,
      "label" => ($type === self::IMPORT ? "NK" : "XK")
        . ($number !== "" ? "-" . $number : "-" . $invoice->id())
        . " (" . date("d/m/Y", strtotime($date)) . ")",
      "field_warehouse" => $warehouse_id,
      "field_type" => $type,
      "field_invoice" => $invoice->id(),
      "field_contact" => $contact_id ?: NULL,
      "field_implementation_date" => $date,
      "field_total_amount" => $total_amount,
      "field_total_vat" => $total_vat,
    ];

    return $this->saveTransaction($values, $detail);
  }

  /**
   * Hóa đơn đã có phiếu kho chưa.
   *
   * Chốt chặn cuối cùng trước khi ghi kho: cờ trên hóa đơn có thể bị đặt lại
   * bằng tay nhưng phiếu đã ghi thì vẫn còn đó.
   *
   * @param InvoiceInterface $invoice
   *   Hóa đơn cần kiểm tra.
   * @param string $type
   *   "import" hoặc "export".
   */
  public function hasTransaction(InvoiceInterface $invoice, string $type): bool {
    $ids = $this->entityTypeManager
      ->getStorage("warehouse_transaction")
      ->getQuery()
      ->condition("bundle", self::HISTORY_BUNDLE)
      ->condition("field_invoice", $invoice->id())
      ->condition("field_type", $type)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    return !empty($ids);
  }

  /**
   * Ngày ghi sổ mặc định của một hóa đơn.
   *
   * @param InvoiceInterface $invoice
   *   Hóa đơn cần đọc.
   */
  public function invoiceDate(InvoiceInterface $invoice): string {
    $date = (string) ($invoice->get("field_invoice_date")->value ?? "");

    return $date !== "" ? substr($date, 0, 10) : date("Y-m-d");
  }

  /**
   * Tên kỳ mặc định theo khoảng ngày.
   */
  public function defaultPeriodLabel(string $start, ?string $end): string {
    $label = $this->t("Opening balance") . " " . date("d/m/Y", strtotime($start));

    return $end ? $label . " - " . date("d/m/Y", strtotime($end)) : (string) $label;
  }

  /**
   * Lưu một phiếu kèm dòng chi tiết.
   *
   * Phiếu được lưu trước để lấy id, sau đó dòng chi tiết mới ghi được quan hệ
   * cha; thiếu quan hệ này thì xoá phiếu sẽ bỏ lại paragraph mồ côi.
   *
   * @param array $values
   *   Giá trị của phiếu.
   * @param array $lines
   *   Danh sách dòng ["item_id", "unit_id" | "unit", "quantity", "price",
   *   "discount", "vat_rate"].
   *
   * @return \Drupal\e_invoice\WarehouseTransactionInterface|null
   *   Phiếu vừa lưu, NULL khi lỗi.
   */
  private function saveTransaction(array $values, array $lines) {
    $transaction = $this->connection->startTransaction();

    try {
      /** @var \Drupal\e_invoice\WarehouseTransactionInterface $entity */
      $entity = $this->entityTypeManager
        ->getStorage("warehouse_transaction")
        ->create($values);

      $entity->save();

      $entity->set("field_detail", $this->buildDetail($entity, $lines));
      $entity->save();

      return $entity;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->loggerFactory->get("erp_e_invoice")->error($e->getMessage());

      return NULL;
    }
  }

  /**
   * Tạo paragraph chi tiết cho một phiếu đã có id.
   *
   * @param \Drupal\e_invoice\WarehouseTransactionInterface $entity
   *   Phiếu đã lưu, cần có id để paragraph ghi được quan hệ cha.
   * @param array $lines
   *   Danh sách dòng ["item_id", "unit_id" | "unit", "quantity", "price",
   *   "discount", "vat_rate"].
   *
   * @return array
   *   Giá trị cho trường field_detail.
   */
  private function buildDetail($entity, array $lines): array {
    $detail = [];
    $units = $this->lineUnits($lines);

    foreach ($lines as $delta => $line) {
      if (empty($line["item_id"])) {
        continue;
      }

      /** @var \Drupal\paragraphs\Entity\Paragraph $paragraph */
      $paragraph = $this->entityTypeManager
        ->getStorage("paragraph")
        ->create([
          "type" => self::DETAIL_BUNDLE,
          "parent_type" => $entity->getEntityTypeId(),
          "parent_id" => $entity->id(),
          "parent_field_name" => "field_detail",
          "field_invoice_item" => $line["item_id"],
          "field_invoice_quantity" => $line["quantity"] ?? 0,
          "field_invoice_price" => $line["price"] ?? 0,
          "field_invoice_discount" => $line["discount"] ?? 0,
          "field_invoice_vat" => $line["vat_rate"] ?? 0,
          "field_invoice_amount" => $line["amount"] ?? $this->lineAmount($line),
        ]);

      // Đặt sau create() để môi trường chưa import trường đơn vị vẫn ghi được
      // phiếu thay vì dựng đứng cả luồng hóa đơn.
      if ($paragraph->hasField("field_invoice_unit")) {
        $paragraph->set("field_invoice_unit", $units[$delta] ?? NULL);
      }

      $paragraph->save();

      $detail[] = [
        "target_id" => $paragraph->id(),
        "target_revision_id" => $paragraph->getRevisionId(),
      ];
    }

    return $detail;
  }

  /**
   * Đơn vị tính của từng dòng, đánh số theo đúng khoá của $lines.
   *
   * Thứ tự ưu tiên: đơn vị dòng chỉ đích danh, rồi tên đơn vị của chứng từ gốc,
   * cuối cùng mới tới đơn vị mua khai ở vật tư. Vật tư được nạp một lượt cho
   * mọi dòng còn thiếu thay vì hỏi lại từng dòng.
   *
   * @param array $lines
   *   Danh sách dòng sắp ghi.
   *
   * @return array
   *   Khoá dòng ánh xạ sang id term đơn vị, NULL khi không tra được.
   */
  private function lineUnits(array $lines): array {
    $units = [];
    $missing = [];

    foreach ($lines as $delta => $line) {
      if (!empty($line["unit_id"])) {
        $units[$delta] = $line["unit_id"];
        continue;
      }

      $name = mb_strtolower(trim((string) ($line["unit"] ?? "")), "UTF-8");

      if ($name !== "" && !empty($this->unitMap()[$name])) {
        $units[$delta] = $this->unitMap()[$name];
        continue;
      }

      $units[$delta] = NULL;

      if (!empty($line["item_id"])) {
        $missing[$delta] = $line["item_id"];
      }
    }

    if (empty($missing)) {
      return $units;
    }

    $supplies = $this->entityTypeManager
      ->getStorage("supplies")
      ->loadMultiple(array_unique($missing));

    foreach ($missing as $delta => $supply_id) {
      /** @var \Drupal\eck\Entity\EckEntity|null $supply */
      $supply = $supplies[$supply_id] ?? NULL;

      $units[$delta] = $supply && $supply->hasField("field_sup_unit_buy")
        ? $supply->get("field_sup_unit_buy")->target_id
        : NULL;
    }

    return $units;
  }

  /**
   * Danh mục đơn vị tính, tên viết thường ánh xạ sang id term.
   */
  private function unitMap(): array {
    if ($this->units !== NULL) {
      return $this->units;
    }

    $this->units = [];

    $terms = $this->entityTypeManager
      ->getStorage("taxonomy_term")
      ->loadByProperties(["vid" => self::UNIT_VOCABULARY]);

    /** @var \Drupal\taxonomy\Entity\Term $term */
    foreach ($terms as $term) {
      $this->units[mb_strtolower($term->label(), "UTF-8")] = $term->id();
    }

    return $this->units;
  }

  /**
   * Thành tiền của một dòng khi chứng từ gốc không ghi sẵn.
   *
   * @param array $line
   *   Dòng có "quantity", "price" và "discount".
   */
  public function lineAmount(array $line): float {
    return (float) ($line["quantity"] ?? 0) * (float) ($line["price"] ?? 0)
      - (float) ($line["discount"] ?? 0);
  }

  /**
   * Xoá paragraph chi tiết không còn được phiếu nào trỏ tới.
   *
   * @param array $ids
   *   Id paragraph cũ.
   */
  private function deleteParagraphs(array $ids): void {
    $ids = array_values(array_filter($ids));

    if (empty($ids)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage("paragraph");
    $storage->delete($storage->loadMultiple($ids));
  }

}

<?php

namespace Drupal\erp_e_invoice\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\erp_e_invoice\InvoiceService;
use Drupal\erp_e_invoice\WarehouseService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Khai báo hoặc cập nhật tồn đầu kỳ cho một kho.
 *
 * Kỳ đầu tiên của kho phải nhập tay số lượng, các kỳ sau bấm kết chuyển để lấy
 * tồn cuối kỳ trước — đó là mối nối giữa bản ghi tồn đầu kỳ và lịch sử nhập
 * xuất: tồn cuối kỳ trước = tồn đầu kỳ trước ± phiếu trong kỳ.
 *
 * Cùng form này dùng lại để cập nhật một kỳ đã khai: kỳ trước bị sửa thì mở kỳ
 * sau lên, bật kết chuyển là số tồn đầu được lấy lại đúng.
 */
class WarehousePeriodForm extends FormBase {

  /**
   * Số dòng trống mở sẵn cho kế toán nhập tay.
   */
  private const EMPTY_ROWS = 5;

  /**
   * Constructs a WarehousePeriodForm object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param WarehouseService $warehouseService
   *   The warehouse service.
   * @param InvoiceService $invoiceService
   *   The invoice service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected WarehouseService $warehouseService,
    protected InvoiceService $invoiceService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get("entity_type.manager"),
      $container->get("erp_e_invoice.warehouse_service"),
      $container->get("erp_e_invoice.invoice_service"),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return "warehouse_period_form";
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $warehouse_transaction = NULL) {
    $request = $this->getRequest();
    $options = $this->warehouseService->warehouseOptions($this->companyIds());

    $balance = $this->loadBalance($form_state, $warehouse_transaction);

    $form["#attributes"]["class"][] = "warehouse-period-form";
    // Kết chuyển tồn và lưu kỳ đều mất vài giây khi kho nhiều vật tư.
    $form["#attached"]["library"][] = "erp_e_invoice/e_invoice_loading";
    // Ô ngày chỉ gọi AJAX khi rời ô, xem js/warehouse-period-date.js.
    $form["#attached"]["library"][] = "erp_e_invoice/warehouse_period_date";

    if (empty($options)) {
      $form["empty"] = [
        "#markup" => "<p class='alert alert-warning'>"
          . $this->t("No warehouse has been created for your company yet.")
          . "</p>",
      ];

      $form["create"] = $this->warehouseLink();

      return $form;
    }

    $form["destination"] = [
      "#type" => "hidden",
      "#default_value" => (string) ($request->query->get("destination") ?? ""),
    ];

    if ($balance) {
      $form["editing"] = [
        "#weight" => -10,
        "#markup" => "<p class='alert alert-info'>"
          . $this->t("Updating the opening balance of period @label.", [
            "@label" => $this->warehouseService->periodLabel($balance),
          ])
          . "</p>",
      ];

      if ($this->warehouseService->isPeriodClosed($balance)) {
        $this->messenger()->addWarning(
          $this->t("A later period was carried forward from this one, update it as well after saving.")
        );
      }
    }

    $range = $this->warehouseService->periodRange($balance);

    $form["warehouse"] = [
      "#type" => "select",
      "#title" => $this->t("Warehouse"),
      "#options" => $options,
      "#required" => TRUE,
      "#default_value" => $balance
        ? $balance->get("field_warehouse")->target_id
        : ($request->query->get("warehouse") ?: array_key_first($options)),
      // Đổi kho của kỳ đã khai là dời cả sổ kho sang kho khác, không phải việc
      // của form này; Drupal vẫn giữ giá trị mặc định khi ô bị khoá.
      "#disabled" => (bool) $balance,
      // Nạp lại bảng dòng thôi, chưa phải lúc soát cả kỳ.
      "#limit_validation_errors" => [["warehouse"], ["start_date"], ["end_date"], ["lines"]],
      "#ajax" => [
        "callback" => "::refreshLines",
        "wrapper" => "warehouse-period-lines",
      ],
    ];

    $form["label"] = [
      "#type" => "textfield",
      "#title" => $this->t("Period name"),
      "#default_value" => $balance?->label(),
    ];

    $form["start_date"] = [
      "#type" => "date",
      "#title" => $this->t("Start date"),
      "#required" => TRUE,
      "#default_value" => $range["start"] ?: ($request->query->get("start") ?: date("Y-m-01")),
      "#limit_validation_errors" => [["warehouse"], ["start_date"], ["end_date"], ["lines"]],
      "#ajax" => [
        "callback" => "::refreshLines",
        "wrapper" => "warehouse-period-lines",
        // Sự kiện của riêng form, chỉ bắn khi ô ngày mất focus và giá trị đã
        // đổi; change của ô date bắn ngay lúc đang gõ.
        "event" => "warehousePeriodDate",
      ],
    ];

    $form["end_date"] = [
      "#type" => "date",
      "#title" => $this->t("End date"),
      "#default_value" => $range["end"] ?: ($balance ? NULL : ($request->query->get("end") ?: date("Y-m-t"))),
      // Ngày kết thúc không đổi bảng dòng nhưng phải soát lại kỳ chồng lấn và
      // thứ tự hai ngày ngay khi vừa nhập.
      "#limit_validation_errors" => [["warehouse"], ["start_date"], ["end_date"], ["lines"]],
      "#ajax" => [
        "callback" => "::refreshLines",
        "wrapper" => "warehouse-period-lines",
        "event" => "warehousePeriodDate",
      ],
    ];

    $form["lines"] = [
      "#type" => "container",
      "#tree" => TRUE,
      "#attributes" => [
        "id" => "warehouse-period-lines",
      ],
    ];

    $warehouse_id = $form_state->getValue("warehouse")
      ?: $form["warehouse"]["#default_value"];
    $start_date = $form_state->getValue("start_date")
      ?: $form["start_date"]["#default_value"];

    $form["lines"]["carry"] = [
      "#type" => "checkbox",
      "#title" => $this->t("Carry the closing balance of the previous period forward"),
      // Kỳ đã khai thì mặc định giữ nguyên số đang có, chỉ kết chuyển lại khi
      // kế toán chủ động bật.
      "#default_value" => !$balance,
      "#limit_validation_errors" => [["warehouse"], ["start_date"], ["end_date"], ["lines"]],
      "#ajax" => [
        "callback" => "::refreshLines",
        "wrapper" => "warehouse-period-lines",
      ],
    ];

    $carry = $form_state->getValue(["lines", "carry"]);
    $carry = $carry === NULL ? !$balance : (bool) $carry;

    // Bảng dòng dựng lại từ kho và ngày mới nên số cũ gửi lên không còn thuộc
    // về đúng vật tư của dòng đó nữa: bỏ đi để bảng lấy giá trị kết chuyển.
    $trigger = $form_state->getTriggeringElement();

    if (!empty($trigger["#name"]) && in_array($trigger["#name"], ["warehouse", "start_date", "lines[carry]"], TRUE)) {
      $input = $form_state->getUserInput();
      unset($input["lines"]["table"]);
      $form_state->setUserInput($input);
    }

    if ($carry) {
      $declared = $this->warehouseService->quantitiesBefore($warehouse_id, $start_date);
    }
    else {
      $declared = $balance ? $this->warehouseService->openingQuantities($balance) : [];
    }

    // Đơn vị tính không có ô nhập trong bảng nên giữ riêng theo vật tư: kết
    // chuyển xong vẫn ghi lại đúng đơn vị của kỳ trước thay vì rơi về đơn vị
    // mua khai ở vật tư.
    $form_state->set("units", array_map(
      static fn (array $line) => $line["unit_id"] ?? NULL,
      $declared
    ));

    $form["lines"]["table"] = $this->buildLineTable($declared, $warehouse_id, $start_date, $carry);

    $form["actions"] = [
      "#type" => "actions",

      "submit" => [
        "#type" => "submit",
        "#value" => $balance
          ? $this->t("Update opening balance")
          : $this->t("Create opening balance"),
        "#attributes" => [
          "class" => ["btn-success"],
        ],
      ],

      "warehouse" => $this->warehouseLink(),
    ];

    return $form;
  }

  /**
   * Bản ghi tồn đầu kỳ đang sửa, NULL khi đang tạo kỳ mới.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param mixed $warehouse_transaction
   *   Tham số đường dẫn, đã được nâng thành entity khi có.
   *
   * @return \Drupal\e_invoice\WarehouseTransactionInterface|null
   *   Bản ghi tồn đầu kỳ.
   */
  private function loadBalance(FormStateInterface $form_state, $warehouse_transaction) {
    // Dựng lại form qua AJAX không còn tham số đường dẫn, phải lấy lại từ
    // form_state nếu không kỳ đang sửa sẽ biến thành kỳ tạo mới giữa chừng.
    if ($warehouse_transaction === NULL) {
      return $form_state->get("balance");
    }

    if (!is_object($warehouse_transaction)) {
      $warehouse_transaction = $this->entityTypeManager
        ->getStorage("warehouse_transaction")
        ->load($warehouse_transaction);
    }

    if (!$warehouse_transaction || $warehouse_transaction->bundle() !== WarehouseService::OPENING_BUNDLE) {
      throw new NotFoundHttpException();
    }

    $form_state->set("balance", $warehouse_transaction);

    return $warehouse_transaction;
  }

  /**
   * Bảng dòng tồn đầu kỳ.
   *
   * @param array $carried
   *   Tồn kết chuyển từ kỳ trước, id vật tư ánh xạ sang số lượng và giá.
   * @param string|int|null $warehouse_id
   *   Kho đang chọn, dùng để báo kỳ chồng lấn ngay khi dựng bảng.
   * @param string|null $start_date
   *   Ngày bắt đầu kỳ đang khai.
   */
  private function buildLineTable(array $carried, string|int|null $warehouse_id, ?string $start_date, bool $carry = TRUE): array {
    $supplies = !empty($carried)
      ? $this->entityTypeManager->getStorage("supplies")->loadMultiple(array_keys($carried))
      : [];

    $table = [
      "#type" => "table",
      "#tree" => TRUE,
      "#caption" => $this->t("Opening quantity by product"),
      "#header" => [
        $this->t("Name"),
        [
          "data" => $this->t("Quantity"),
          "class" => ["w-15", "text-center"],
        ],
        [
          "data" => $this->t("Price"),
          "class" => ["w-15", "text-center"],
        ],
      ],
      "#empty" => $this->t("No product to carry forward, enter the opening quantity by hand."),
    ];

    $previous = $carry && $warehouse_id && $start_date
      ? $this->warehouseService->findPreviousPeriod(
        $warehouse_id,
        date("Y-m-d", strtotime($start_date . " -1 day"))
      )
      : NULL;

    if (!empty($carried) && $previous) {
      $table["#prefix"] = "<p class='text-muted small mb-1'>"
        . $this->t("Carried forward from") . ": " . $this->warehouseService->periodLabel($previous)
        . "</p>";
    }

    $delta = 0;

    foreach ($carried as $supply_id => $line) {
      /** @var \Drupal\eck\Entity\EckEntity|null $supply */
      $supply = $supplies[$supply_id] ?? NULL;

      // Vật tư đã bị xoá thì không kết chuyển được, bỏ dòng thay vì tạo bản
      // ghi trỏ vào entity không còn tồn tại.
      if (!$supply) {
        continue;
      }

      $table[$delta] = [
        "item" => [
          "#type" => "entity_autocomplete",
          "#target_type" => "supplies",
          "#default_value" => $supply,
          "#selection_settings" => [
            "target_bundles" => ["supplies", "service", "asset", "legal_object"],
          ],
        ],
        "quantity" => [
          "#type" => "number",
          "#step" => "0.01",
          "#default_value" => $line["quantity"],
          "#title_display" => "invisible",
          "#title" => $this->t("Quantity"),
        ],
        "price" => [
          "#type" => "number",
          "#step" => "0.01",
          "#default_value" => $line["price"],
          "#title_display" => "invisible",
          "#title" => $this->t("Price"),
        ],
      ];

      $delta++;
    }

    for ($row = 0; $row < self::EMPTY_ROWS; $row++) {
      $table[$delta + $row] = [
        "item" => [
          "#type" => "entity_autocomplete",
          "#target_type" => "supplies",
          "#placeholder" => $this->t("Search for goods and services"),
          "#selection_settings" => [
            "target_bundles" => ["supplies", "service", "asset", "legal_object"],
          ],
        ],
        "quantity" => [
          "#type" => "number",
          "#step" => "0.01",
          "#title_display" => "invisible",
          "#title" => $this->t("Quantity"),
        ],
        "price" => [
          "#type" => "number",
          "#step" => "0.01",
          "#title_display" => "invisible",
          "#title" => $this->t("Price"),
        ],
      ];
    }

    return $table;
  }

  /**
   * Nút mở form tạo kho.
   */
  private function warehouseLink(): array {
    return [
      "#type" => "link",
      "#title" => $this->t("Create warehouse"),
      "#url" => Url::fromRoute("entity.warehouse_invoice.add_form", [
        "warehouse_invoice_type" => WarehouseService::WAREHOUSE_BUNDLE,
      ], [
        "query" => [
          "destination" => $this->getRequest()->getRequestUri(),
        ],
      ]),
      "#attributes" => [
        "class" => ["btn", "btn-outline-primary"],
      ],
    ];
  }

  /**
   * Dựng lại bảng dòng khi đổi kho, đổi ngày hoặc bật tắt kết chuyển.
   */
  public function refreshLines(array &$form, FormStateInterface $form_state) {
    return $form["lines"];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $warehouse_id = $form_state->getValue("warehouse");
    $start = $form_state->getValue("start_date");
    $end = $form_state->getValue("end_date") ?: NULL;

    if (empty($warehouse_id) || empty($start)) {
      return;
    }

    if ($end && $end < $start) {
      $form_state->setErrorByName("end_date", $this->t("The end date must not be earlier than the start date."));
    }

    $balance = $form_state->get("balance");

    $overlap = $this->warehouseService->findOverlappingPeriod(
      $warehouse_id,
      $start,
      $end,
      $balance?->id()
    );

    if ($overlap) {
      $form_state->setErrorByName(
        "start_date",
        $this->t("This period overlaps an existing one.") . " "
          . $this->warehouseService->periodLabel($overlap)
      );
    }

    $seen = [];

    foreach ($form_state->getValue(["lines", "table"]) ?: [] as $delta => $line) {
      $supply_id = $line["item"] ?? NULL;

      if (empty($supply_id)) {
        continue;
      }

      // Cùng một vật tư khai hai dòng sẽ cho ra hai số tồn đầu kỳ khác nhau.
      if (isset($seen[$supply_id])) {
        $form_state->setErrorByName(
          "lines][table][$delta][item",
          $this->t("This product is already declared in this period.")
        );
        continue;
      }

      $seen[$supply_id] = $delta;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $lines = [];
    $units = $form_state->get("units") ?: [];

    foreach ($form_state->getValue(["lines", "table"]) ?: [] as $line) {
      if (empty($line["item"])) {
        continue;
      }

      $lines[] = [
        "item_id" => $line["item"],
        // Dòng kế toán tự thêm chưa có đơn vị, WarehouseService lấy đơn vị mua
        // của vật tư cho những dòng này.
        "unit_id" => $units[$line["item"]] ?? NULL,
        "quantity" => (float) ($line["quantity"] ?? 0),
        "price" => (float) ($line["price"] ?? 0),
      ];
    }

    $existing = $form_state->get("balance");

    $balance = $existing
      ? $this->warehouseService->updateOpeningBalance(
        $existing,
        $form_state->getValue("start_date"),
        $form_state->getValue("end_date") ?: NULL,
        $lines,
        trim((string) $form_state->getValue("label")) ?: NULL
      )
      : $this->warehouseService->createOpeningBalance(
        $form_state->getValue("warehouse"),
        $form_state->getValue("start_date"),
        $form_state->getValue("end_date") ?: NULL,
        $lines,
        trim((string) $form_state->getValue("label")) ?: NULL
      );

    if (empty($balance)) {
      $this->messenger()->addError($existing
        ? $this->t("The opening balance could not be updated.")
        : $this->t("The opening balance could not be created.")
      );
      return;
    }

    $this->messenger()->addMessage($existing
      ? $this->t("Opening balance @label has been updated with @count products.", [
        "@label" => $balance->label(),
        "@count" => count($lines),
      ])
      : $this->t("Opening balance @label has been created with @count products.", [
        "@label" => $balance->label(),
        "@count" => count($lines),
      ])
    );

    $form_state->setResponse(new RedirectResponse($this->invoiceService->safeRedirect(
      $form_state->getValue("destination"),
      "erp_e_invoice.e_invoice_list_warehouse"
    )));
  }

  /**
   * Công ty người dùng được xem, dùng để lọc danh sách kho.
   */
  private function companyIds(): array {
    /** @var \Drupal\user\Entity\User|null $user */
    $user = $this->entityTypeManager
      ->getStorage("user")
      ->load($this->currentUser()->id());

    if (!$user) {
      return [];
    }

    return $this->invoiceService->companyScope(
      array_keys($this->invoiceService->userCompanies($user))
    );
  }

}

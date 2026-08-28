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
 * Đối chiếu dòng hóa đơn với vật tư rồi tạo phiếu nhập/xuất kho.
 *
 * Form nhận một hoặc nhiều hóa đơn cùng lúc. Vật tư và đối tác được gom theo
 * tên: một tên hàng xuất hiện ở mười hóa đơn thì kế toán chỉ đối chiếu một lần,
 * còn phiếu kho vẫn tạo riêng cho từng hóa đơn.
 */
class UpdateInvoiceItemsForm extends FormBase {

  /**
   * Vật tư đã đối chiếu xong và được phép ghi kho.
   */
  private const ITEM_MATCHED = 1;

  /**
   * Kế toán chọn không nhập / xuất dòng này.
   */
  private const ITEM_SKIPPED = 2;

  /**
   * Constructs an UpdateInvoiceItemsForm object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param InvoiceService $invoiceService
   *   The invoice service.
   * @param WarehouseService $warehouseService
   *   The warehouse service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InvoiceService $invoiceService,
    protected WarehouseService $warehouseService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get("entity_type.manager"),
      $container->get("erp_e_invoice.invoice_service"),
      $container->get("erp_e_invoice.warehouse_service"),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return "update_invoice_items_form";
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uuid = NULL) {
    $invoices = $this->loadInvoices($uuid);

    // Trộn hóa đơn vào và hóa đơn ra thì không biết phải tạo phiếu nhập hay
    // phiếu xuất, chỉ giữ lại nhóm của hóa đơn đầu tiên.
    $bundle = reset($invoices)->bundle();
    $mixed = FALSE;

    foreach ($invoices as $id => $invoice) {
      if ($invoice->bundle() !== $bundle) {
        unset($invoices[$id]);
        $mixed = TRUE;
      }
    }

    if ($mixed) {
      $this->messenger()->addWarning(
        $this->t("Input and output invoices cannot be processed together, only invoices of the same type are kept.")
      );
    }

    $is_export = $bundle === "output_invoices";
    $handle_field = $is_export ? "field_invoice_export" : "field_invoice_import";

    // Hóa đơn đã nhập / xuất rồi chỉ hiện để kế toán biết, không đưa vào form.
    $pending = [];
    $handled = [];

    foreach ($invoices as $invoice) {
      if ($this->invoiceService->isHandled($invoice, $handle_field)) {
        $handled[] = $invoice;
        continue;
      }

      $pending[$invoice->id()] = $invoice;
    }

    $contacts = $this->collectContacts($pending, $is_export);
    $items = $this->collectItems($pending);
    $type_list = $this->invoiceService->loadTerm("supplies_type", "id");

    $form_state->set("pending", $pending);
    $form_state->set("companies", $this->collectCompanies($pending));
    $form_state->set("contacts", $contacts);
    $form_state->set("items", $items);
    $form_state->set("is_export", $is_export);
    $form_state->set("handle_field", $handle_field);

    $form["#attributes"]["class"][] = "invoice-items-form";
    $form["#attached"]["library"][] = "erp_e_invoice/e_invoice_update";

    $form["uuids"] = [
      "#type" => "hidden",
      "#value" => implode(",", array_map(static fn ($invoice) => $invoice->uuid(), $invoices)),
    ];

    $form["destination"] = [
      "#type" => "hidden",
      "#value" => $this->destination(),
    ];

    $form["summary"] = $this->buildInvoiceSummary($pending, $handled, $is_export);

    if (empty($pending)) {
      $form["back"] = [
        "#type" => "link",
        "#title" => $this->t("Back"),
        "#url" => $this->listUrl($is_export),
        "#attributes" => [
          "class" => ["btn", "btn-secondary"],
        ],
      ];

      return $form;
    }

    $form["warehouse"] = $this->buildWarehouseSelect($form_state, $pending);
    $form["invoice_supplier"] = $this->buildContactTable($contacts, $is_export);
    $form["invoice_item"] = $this->buildItemTable($items, $type_list);
    $form["item_matched"] = $this->buildMatchedItems($items);

    $form["actions"] = [
      "#type" => "actions",

      "submit" => [
        "#type" => "submit",
        "#value" => $is_export ? $this->t("Create export") : $this->t("Create import"),
        "#attributes" => [
          "class" => ["btn-success"],
        ],
      ],

      "close" => [
        "#type" => "submit",
        "#submit" => ["::submitClose"],
        "#limit_validation_errors" => [],
        "#value" => $is_export ? $this->t("Not export") : $this->t("Not import"),
        "#attributes" => [
          "class" => ["btn-danger"],
        ],
      ],
    ];

    return $form;
  }

  /**
   * Bảng tóm tắt các hóa đơn đang xử lý.
   *
   * @param array $pending
   *   Hóa đơn còn phải tạo phiếu.
   * @param array $handled
   *   Hóa đơn đã nhập / xuất trước đó.
   * @param bool $is_export
   *   TRUE khi đang xử lý hóa đơn đầu ra.
   */
  private function buildInvoiceSummary(array $pending, array $handled, bool $is_export): array {
    $total = 0;
    $rows = [];

    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    foreach (array_merge($pending, $handled) as $invoice) {
      $amount = (float) ($invoice->get("field_invoice_total_amount")->value ?? 0);
      $total += $amount;

      $rows[] = [
        "no" => [
          "#markup" => $invoice->get("field_invoice_no")->value ?: $invoice->label(),
        ],
        "date" => [
          "#markup" => $invoice->get("field_invoice_date")->value
            ? date("d/m/Y", strtotime($invoice->get("field_invoice_date")->value))
            : "",
        ],
        "contact" => [
          "#markup" => $this->contactName($invoice, $is_export),
        ],
        "amount" => [
          "#markup" => "<span class='d-block text-end'>" . number_format($amount, 0, ",", ".") . "</span>",
        ],
        "state" => [
          "#markup" => isset($pending[$invoice->id()])
            ? "<span class='text-primary'>" . $this->t("Waiting") . "</span>"
            : "<span class='text-muted'>" . $this->t("This invoice has already been processed.") . "</span>",
        ],
      ];
    }

    return [
      "#type" => "details",
      "#open" => count($rows) <= 10,
      "#title" => $this->t("Selected invoices") . ": " . count($rows)
        . " — " . $this->t("Total amount") . ": " . number_format($total, 0, ",", "."),

      "table" => [
        "#type" => "table",
        "#header" => [
          $this->t("Invoice no"),
          $this->t("Invoice date"),
          $is_export ? $this->t("Buyer name") : $this->t("Seller name"),
          [
            "data" => $this->t("Total amount"),
            "class" => ["text-end"],
          ],
          $this->t("Invoice status"),
        ],
      ] + $rows,
    ];
  }

  /**
   * Ô chọn kho và kỳ tồn đầu kỳ sẽ ghi phiếu vào.
   *
   * Kho lọc theo công ty của chính các hóa đơn đang xử lý: ghi hóa đơn của công
   * ty này vào kho của công ty khác là sai sổ, không phải chuyện kế toán chọn.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param array $pending
   *   Hóa đơn còn phải tạo phiếu.
   */
  private function buildWarehouseSelect(FormStateInterface $form_state, array $pending): array {
    $companies = $form_state->get("companies") ?: [];
    $options = $this->warehouseService->warehouseOptions($companies);

    $section = [
      "#type" => "details",
      "#open" => TRUE,
      "#title" => $this->t("Warehouse"),
      "#tree" => TRUE,
    ];

    if (empty($options)) {
      $section["empty"] = [
        "#markup" => "<p class='alert alert-warning mb-2'>"
          . $this->t("No warehouse has been created for your company yet.")
          . "</p>",
      ];

      $section["create_warehouse"] = $this->createWarehouseLink();

      return $section;
    }

    $warehouse_id = $form_state->getValue(["warehouse", "warehouse_id"])
      ?: array_key_first($options);

    $section["warehouse_id"] = [
      "#type" => "select",
      "#title" => $this->t("Warehouse"),
      "#options" => $options,
      "#required" => TRUE,
      "#default_value" => $warehouse_id,
      // Đổi kho chỉ để nạp lại danh sách kỳ, không phải lúc bắt kế toán điền
      // xong cả bảng đối chiếu.
      "#limit_validation_errors" => [["warehouse"]],
      "#ajax" => [
        "callback" => "::refreshPeriod",
        "wrapper" => "invoice-warehouse-period",
      ],
    ];

    $section["period"] = [
      "#type" => "container",
      "#attributes" => [
        "id" => "invoice-warehouse-period",
      ],
    ];

    $period_options = $this->warehouseService->periodOptions($warehouse_id);

    // Kỳ vừa chọn thuộc kho cũ thì phải bỏ khỏi dữ liệu gửi lên, nếu không ô
    // chọn kỳ giữ nguyên giá trị không còn hợp lệ và Drupal báo lựa chọn sai.
    $input = $form_state->getUserInput();
    $submitted = $input["warehouse"]["period"]["period_id"] ?? NULL;

    if ($submitted !== NULL && !isset($period_options[$submitted])) {
      unset($input["warehouse"]["period"]["period_id"]);
      $form_state->setUserInput($input);
    }

    if (empty($period_options)) {
      $section["period"]["notice"] = [
        "#markup" => "<p class='alert alert-warning mb-2'>"
          . $this->t("This warehouse has no opening balance yet, the report will start from zero.")
          . "</p>",
      ];

      $section["period"]["create_period"] = $this->createPeriodLink($warehouse_id, $pending);
      $section["period"]["create_warehouse"] = $this->createWarehouseLink();

      return $section;
    }

    // Kỳ mặc định là kỳ chứa hóa đơn sớm nhất, đúng chỗ phiếu sẽ rơi vào.
    $default = $this->warehouseService->findPeriod($warehouse_id, $this->earliestDate($pending));

    $section["period"]["period_id"] = [
      "#type" => "select",
      "#title" => $this->t("Opening balance period"),
      "#options" => $period_options,
      "#required" => TRUE,
      "#default_value" => $default ? $default->id() : array_key_first($period_options),
    ];

    $section["period"]["create_period"] = $this->createPeriodLink($warehouse_id, $pending);
    $section["period"]["create_warehouse"] = $this->createWarehouseLink();

    return $section;
  }

  /**
   * Nút mở form tạo kho, quay lại đúng danh sách hóa đơn đang xử lý.
   */
  private function createWarehouseLink(): array {
    return [
      "#type" => "link",
      "#title" => $this->t("Create warehouse"),
      "#url" => Url::fromRoute("entity.warehouse_invoice.add_form", [
        "warehouse_invoice_type" => WarehouseService::WAREHOUSE_BUNDLE,
      ], [
        "query" => [
          "destination" => $this->currentPath(),
        ],
      ]),
      "#attributes" => [
        "class" => ["btn", "btn-outline-primary", "btn-sm"],
      ],
    ];
  }

  /**
   * Nút mở form khai tồn đầu kỳ, mở sẵn kho và tháng của hóa đơn sớm nhất.
   *
   * @param string|int $warehouse_id
   *   Kho đang chọn.
   * @param array $pending
   *   Hóa đơn còn phải tạo phiếu.
   */
  private function createPeriodLink(string|int $warehouse_id, array $pending): array {
    $date = $this->earliestDate($pending);

    return [
      "#type" => "link",
      "#title" => $this->t("Create opening balance"),
      "#url" => Url::fromRoute("erp_e_invoice.e_invoice_warehouse_period", [], [
        "query" => [
          "warehouse" => $warehouse_id,
          "start" => date("Y-m-01", strtotime($date)),
          "end" => date("Y-m-t", strtotime($date)),
          "destination" => $this->currentPath(),
        ],
      ]),
      "#attributes" => [
        "class" => ["btn", "btn-outline-primary", "btn-sm"],
      ],
    ];
  }

  /**
   * Đường dẫn quay lại chính form này sau khi tạo kho hoặc tạo kỳ.
   *
   * Form nhận nhiều hóa đơn qua POST nên phải dựng lại đường dẫn kèm uuid,
   * nếu không kế toán quay lại sẽ mất danh sách vừa chọn.
   */
  private function currentPath(): string {
    $request = $this->getRequest();
    $uuids = (string) ($request->request->get("uuids") ?: $request->query->get("uuids") ?: "");

    if ($uuids === "") {
      return $request->getRequestUri();
    }

    return Url::fromRoute("erp_e_invoice.e_invoice_update_items_multiple", [], [
      "query" => [
        "uuids" => $uuids,
        "destination" => $this->destination(),
      ],
    ])->toString();
  }

  /**
   * Ngày hóa đơn sớm nhất trong nhóm đang xử lý.
   *
   * @param array $pending
   *   Hóa đơn còn phải tạo phiếu.
   */
  private function earliestDate(array $pending): string {
    $dates = [];

    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    foreach ($pending as $invoice) {
      $dates[] = $this->warehouseService->invoiceDate($invoice);
    }

    return !empty($dates) ? min($dates) : date("Y-m-d");
  }

  /**
   * Công ty của các hóa đơn đang xử lý, kèm công ty con.
   *
   * @param array $pending
   *   Hóa đơn còn phải tạo phiếu.
   */
  private function collectCompanies(array $pending): array {
    $companies = [];

    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    foreach ($pending as $invoice) {
      $company_id = $invoice->get("field_invoice_company")->target_id;

      if (!empty($company_id)) {
        $companies[$company_id] = $company_id;
      }
    }

    return $this->invoiceService->companyScope($companies);
  }

  /**
   * Dựng lại ô chọn kỳ khi kế toán đổi kho.
   */
  public function refreshPeriod(array &$form, FormStateInterface $form_state) {
    return $form["warehouse"]["period"];
  }

  /**
   * Bảng đối chiếu đối tác: mỗi tên chỉ hỏi kế toán một lần.
   *
   * @param array $contacts
   *   Danh sách đối tác đã gom theo tên.
   * @param bool $is_export
   *   TRUE khi đang xử lý hóa đơn đầu ra.
   */
  private function buildContactTable(array $contacts, bool $is_export): array {
    $table = [
      "#type" => "table",
      "#tree" => TRUE,
      "#caption" => $this->t("Partner"),
      "#header" => [
        $is_export ? $this->t("Buyer name") : $this->t("Seller name"),
        [
          "data" => $this->t("Taxcode"),
          "class" => ["w-15", "text-center"],
        ],
        [
          "data" => $this->t("Number of invoices"),
          "class" => ["w-10", "text-center"],
        ],
        [
          "data" => $this->t("Select"),
          "class" => ["w-15", "text-center"],
        ],
        [
          "data" => $this->t("Action"),
          "class" => ["w-25", "text-center"],
        ],
      ],
      "#empty" => $this->t("Not found invoice"),
    ];

    foreach ($contacts as $key => $contact) {
      $table[$key] = [
        "name" => [
          "#markup" => "<span class='fw-medium'>"
            . ($contact["name"] !== "" ? $contact["name"] : $this->t("No name"))
            . "</span><small class='d-block text-muted'>"
            . $this->t("Invoice no") . ": " . implode(", ", $contact["invoices"])
            . "</small>",
        ],
        "taxcode" => [
          "#markup" => "<p class='mb-0 text-center'>" . $contact["taxcode"] . "</p>",
        ],
        "count" => [
          "#markup" => "<p class='mb-0 text-center'>" . count($contact["invoices"]) . "</p>",
        ],
      ];

      if (!empty($contact["id"])) {
        $table[$key]["action"] = [
          "#type" => "hidden",
          "#value" => "exist",
        ];

        $table[$key]["contact_wrapper"] = [
          "#markup" => "<p class='mb-0 text-center text-success'>" . $this->t("Available") . "</p>",
        ];

        continue;
      }

      $table[$key]["action"] = [
        "#type" => "select",
        "#attributes" => [
          "class" => ["mx-auto"],
        ],
        "#options" => $contact["name"] !== ""
          ? [
            "select" => $this->t("Available"),
            "create" => $this->t("Create new"),
          ]
          : [
            "select" => $this->t("Available"),
          ],
      ];

      $table[$key]["contact_wrapper"] = [
        "#type" => "entity_autocomplete",
        "#placeholder" => $this->t("Search for supplier"),
        "#target_type" => "crm",
        "#selection_settings" => [
          "target_bundles" => ["customers"],
        ],
        "#states" => [
          "visible" => [
            ':input[name="invoice_supplier[' . $key . '][action]"]' => ["value" => "select"],
          ],
          "required" => [
            ':input[name="invoice_supplier[' . $key . '][action]"]' => ["value" => "select"],
          ],
        ],
      ];
    }

    return $table;
  }

  /**
   * Bảng đối chiếu vật tư còn thiếu, gom theo tên hàng của mọi hóa đơn.
   *
   * @param array $items
   *   Danh sách dòng hàng đã gom theo tên.
   * @param array $type_list
   *   Danh mục nhóm vật tư.
   */
  private function buildItemTable(array $items, array $type_list): array {
    $table = [
      "#type" => "table",
      "#tree" => TRUE,
      "#caption" => $this->t("Goods and services"),
      "#header" => [
        $this->t("Name"),
        [
          "data" => $this->t("Unit"),
          "class" => ["w-10", "text-center"],
        ],
        [
          "data" => $this->t("Price"),
          "class" => ["w-10", "text-center"],
        ],
        [
          "data" => $this->t("Select"),
          "class" => ["w-15", "text-center"],
        ],
        [
          "data" => $this->t("Action"),
          "class" => ["w-25", "text-center"],
        ],
      ],
      "#empty" => $this->t("All goods have been matched."),
    ];

    foreach ($items as $key => $item) {
      // Vật tư đã khớp không cần kế toán quyết định, để riêng cho bảng gọn.
      if (!empty($item["id"])) {
        continue;
      }

      $table[$key] = [
        "name" => [
          "#markup" => "<span class='fw-medium'>" . $item["name"] . "</span>"
            . "<small class='d-block text-muted'>"
            . $this->t("Used in @count invoices", ["@count" => count($item["invoices"])])
            . "</small>",
        ],
        "unit" => [
          "#markup" => "<p class='mb-0 text-center'>" . $item["unit"] . "</p>",
        ],
        "price" => [
          "#markup" => "<p class='mb-0 text-center'>" . $this->priceLabel($item) . "</p>",
        ],

        "action" => [
          "#type" => "select",
          "#attributes" => [
            "class" => ["mx-auto", "invoice-item-action"],
          ],
          "#options" => [
            "select" => $this->t("Available"),
            "create" => $this->t("Create new"),
            "none" => $this->t("Unimport"),
          ],
        ],

        "product_wrapper" => [
          "#type" => "container",

          "exit_product" => [
            "#type" => "textfield",
            "#placeholder" => $this->t("Search for goods and services"),
            "#autocomplete_route_name" => "entity_reference_modal.autocomplete",
            "#autocomplete_route_parameters" => [
              "selection_settings_key" => $this->invoiceService->getAutocompleteSettingsKey(),
            ],
            "#attributes" => [
              "class" => ["input-search-product"],
            ],
            "#states" => [
              "visible" => [
                ':input[name="invoice_item[' . $key . '][action]"]' => ["value" => "select"],
              ],
              "required" => [
                ':input[name="invoice_item[' . $key . '][action]"]' => ["value" => "select"],
              ],
            ],
          ],

          "new_product" => [
            "#type" => "container",
            "#attributes" => [
              "class" => ["d-flex gap-2"],
            ],

            "code_product" => [
              "#type" => "textfield",
              "#placeholder" => $this->t("Internal code"),
              "#states" => [
                "visible" => [
                  ':input[name="invoice_item[' . $key . '][action]"]' => ["value" => "create"],
                ],
                "required" => [
                  ':input[name="invoice_item[' . $key . '][action]"]' => ["value" => "create"],
                ],
              ],
            ],

            "type_product" => [
              "#type" => "select",
              "#options" => $type_list,
              "#attributes" => [
                "style" => ["width: 100px !important"],
              ],
              "#states" => [
                "visible" => [
                  ':input[name="invoice_item[' . $key . '][action]"]' => ["value" => "create"],
                ],
                "required" => [
                  ':input[name="invoice_item[' . $key . '][action]"]' => ["value" => "create"],
                ],
              ],
            ],
          ],
        ],
      ];
    }

    // Ô chọn nhanh chỉ có ích khi còn nhiều dòng phải quyết định.
    if (count(array_filter($items, static fn (array $item) => empty($item["id"]))) > 1) {
      $table["#prefix"] = '<div class="d-flex align-items-center gap-2 mb-2">'
        . '<span class="text-nowrap">' . $this->t("Apply to all") . ':</span>'
        . '<select class="form-select form-select-sm w-auto invoice-bulk-action">'
        . '<option value="">--</option>'
        . '<option value="select">' . $this->t("Available") . '</option>'
        . '<option value="create">' . $this->t("Create new") . '</option>'
        . '<option value="none">' . $this->t("Unimport") . '</option>'
        . '</select></div>';
    }

    return $table;
  }

  /**
   * Danh sách vật tư đã khớp sẵn, chỉ để kế toán soát lại.
   *
   * @param array $items
   *   Danh sách dòng hàng đã gom theo tên.
   */
  private function buildMatchedItems(array $items): array {
    $rows = [];

    foreach ($items as $item) {
      if (empty($item["id"])) {
        continue;
      }

      $rows[] = [
        "name" => [
          "#markup" => $item["name"],
        ],
        "unit" => [
          "#markup" => "<p class='mb-0 text-center'>" . $item["unit"] . "</p>",
        ],
        "count" => [
          "#markup" => "<p class='mb-0 text-center'>" . count($item["invoices"]) . "</p>",
        ],
      ];
    }

    if (empty($rows)) {
      return [];
    }

    return [
      "#type" => "details",
      "#open" => FALSE,
      "#title" => $this->t("Existing product") . ": " . count($rows),

      "table" => [
        "#type" => "table",
        "#header" => [
          $this->t("Name"),
          [
            "data" => $this->t("Unit"),
            "class" => ["w-10", "text-center"],
          ],
          [
            "data" => $this->t("Number of invoices"),
            "class" => ["w-10", "text-center"],
          ],
        ],
      ] + $rows,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $this->validateWarehouse($form_state);

    $contacts = $form_state->get("contacts") ?: [];
    $items = $form_state->get("items") ?: [];

    $contact_values = $form_state->getValue("invoice_supplier") ?: [];
    $item_values = $form_state->getValue("invoice_item") ?: [];
    $new_codes = [];

    // Chưa tìm được đối tác mà kế toán chọn "Có sẵn" thì bắt buộc phải chỉ ra
    // đối tác — #states chỉ ràng buộc phía trình duyệt.
    foreach ($contacts as $key => $contact) {
      if (!empty($contact["id"])) {
        continue;
      }

      $value = $contact_values[$key] ?? [];

      if (($value["action"] ?? "") === "select" && empty($value["contact_wrapper"])) {
        $form_state->setErrorByName(
          "invoice_supplier][$key][contact_wrapper",
          $this->t("Please select a valid supplier.") . " "
            . ($contact["name"] !== "" ? $contact["name"] : implode(", ", $contact["invoices"]))
        );
      }
    }

    foreach ($items as $key => $item) {
      if (!empty($item["id"])) {
        continue;
      }

      $value = $item_values[$key] ?? [];

      if (($value["action"] ?? "") === "select") {
        $product = $value["product_wrapper"]["exit_product"] ?? "";
        if (empty($product) || !preg_match("/\(\d+\)$/", $product)) {
          $form_state->setErrorByName(
            "invoice_item][$key][product_wrapper][exit_product",
            $this->t("Please select a valid product.") . " " . $item["name"]
          );
        }

        continue;
      }

      if (($value["action"] ?? "") !== "create") {
        continue;
      }

      $code = trim((string) ($value["product_wrapper"]["new_product"]["code_product"] ?? ""));

      if ($code === "") {
        $form_state->setErrorByName(
          "invoice_item][$key][product_wrapper][new_product][code_product",
          $this->t("Internal code is required.")
        );
        continue;
      }

      // Hai dòng cùng khai một mã nội bộ sẽ tạo ra hai vật tư trùng mã.
      if (isset($new_codes[$code])) {
        $form_state->setErrorByName(
          "invoice_item][$key][product_wrapper][new_product][code_product",
          $this->t("Internal code already exists.") . " " . $code
        );
        continue;
      }

      $new_codes[$code] = $key;
    }

    if (empty($new_codes)) {
      return;
    }

    // Một truy vấn cho mọi mã mới thay vì mỗi dòng một truy vấn.
    $existing = $this->entityTypeManager
      ->getStorage("supplies")
      ->getQuery()
      ->condition("field_sup_code", array_keys($new_codes), "IN")
      ->accessCheck(FALSE)
      ->execute();

    if (empty($existing)) {
      return;
    }

    $supplies = $this->entityTypeManager
      ->getStorage("supplies")
      ->loadMultiple($existing);

    /** @var \Drupal\eck\Entity\EckEntity $supply */
    foreach ($supplies as $supply) {
      $code = $supply->get("field_sup_code")->value;

      if (!isset($new_codes[$code])) {
        continue;
      }

      $form_state->setErrorByName(
        "invoice_item][" . $new_codes[$code] . "][product_wrapper][new_product][code_product",
        $this->t("Internal code already exists.") . " " . $code
      );
    }
  }

  /**
   * Kho và kỳ nhận phiếu có hợp lệ không.
   *
   * Kỳ đã khoá hoặc hóa đơn rơi ra ngoài kỳ đều làm sổ kho lệch, chặn ngay ở
   * đây thay vì để báo cáo cuối kỳ mới lộ ra.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   */
  private function validateWarehouse(FormStateInterface $form_state): void {
    $warehouse_id = $form_state->getValue(["warehouse", "warehouse_id"]);

    if (empty($warehouse_id)) {
      $form_state->setErrorByName(
        "warehouse][warehouse_id",
        $this->t("Please select a warehouse.")
      );

      return;
    }

    $period_id = $form_state->getValue(["warehouse", "period", "period_id"]);

    // Kho chưa khai tồn đầu kỳ vẫn ghi phiếu được, báo cáo coi tồn đầu bằng 0.
    if (empty($period_id)) {
      return;
    }

    $period = $this->entityTypeManager
      ->getStorage("warehouse_transaction")
      ->load($period_id);

    if (!$period) {
      $form_state->setErrorByName(
        "warehouse][period][period_id",
        $this->t("Please select a valid opening balance period.")
      );

      return;
    }

    if ($this->warehouseService->isPeriodClosed($period)) {
      $form_state->setErrorByName(
        "warehouse][period][period_id",
        $this->t("This period is closed because a later opening balance was carried forward from it.")
      );

      return;
    }

    $range = $this->warehouseService->periodRange($period);
    $outside = [];

    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    foreach ($form_state->get("pending") ?: [] as $invoice) {
      $date = $this->warehouseService->invoiceDate($invoice);

      if (!empty($range["start"]) && $date < $range["start"]) {
        $outside[] = $invoice->get("field_invoice_no")->value ?: $invoice->label();
        continue;
      }

      if (!empty($range["end"]) && $date > $range["end"]) {
        $outside[] = $invoice->get("field_invoice_no")->value ?: $invoice->label();
      }
    }

    if (!empty($outside)) {
      $form_state->setErrorByName(
        "warehouse][period][period_id",
        $this->t("These invoices are dated outside the selected period.")
          . " " . implode(", ", $outside)
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $pending = $form_state->get("pending") ?: [];
    $is_export = (bool) $form_state->get("is_export");
    $handle_field = $form_state->get("handle_field");

    $this->redirectBack($form_state, $is_export);

    if (empty($pending)) {
      $this->messenger()->addWarning($this->t("This invoice has already been processed."));
      return;
    }

    $warehouse_id = $form_state->getValue(["warehouse", "warehouse_id"]);
    if (empty($warehouse_id)) {
      $this->messenger()->addError($this->t("Please select a warehouse."));
      return;
    }

    $units = $this->invoiceService->loadTerm();

    $contact_ids = $this->resolveContacts($form_state);
    $item_map = $this->resolveItems($form_state, $units);

    $created = 0;
    $failed = [];
    $empty_lines = [];
    $no_contact = [];

    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    foreach ($pending as $invoice) {
      // Hóa đơn có thể vừa được xử lý ở tab khác trong lúc kế toán điền form.
      if ($this->invoiceService->isHandled($invoice, $handle_field)) {
        continue;
      }

      $label = $invoice->get("field_invoice_no")->value ?: $invoice->label();
      $has_line = FALSE;
      $lines = $invoice->get("field_invoice_items")->getValue();

      foreach ($lines as $key => $line) {
        $map = $item_map[$line["item_name"] ?? ""] ?? NULL;

        if ($map === NULL) {
          continue;
        }

        $lines[$key]["item_id"] = $map["id"];
        $lines[$key]["item_exist"] = $map["exist"];

        $has_line = $has_line || (!empty($map["id"]) && $map["exist"] === self::ITEM_MATCHED);
      }

      $invoice->set("field_invoice_items", $lines);

      // Không còn dòng nào để ghi kho (kế toán chọn không nhập/xuất hết) thì
      // đóng hóa đơn luôn thay vì báo lỗi tạo phiếu.
      if (!$has_line) {
        $invoice->set($handle_field, self::ITEM_SKIPPED);
        $invoice->save();
        $empty_lines[] = $label;
        continue;
      }

      $contact_id = $contact_ids[$this->contactName($invoice, $is_export)] ?? NULL;

      if (empty($contact_id)) {
        $invoice->save();
        $no_contact[] = $label;
        continue;
      }

      $invoice->save();

      $document = $is_export
        ? $this->invoiceService->createExport($invoice, $contact_id, $warehouse_id)
        : $this->invoiceService->createImport($invoice, $contact_id, $warehouse_id);

      // createExport/createImport nuốt ngoại lệ và trả FALSE, phải đọc kết quả
      // trả về mới biết phiếu có thực sự được tạo hay không.
      if (!$document) {
        $failed[] = $label;
        continue;
      }

      $created++;
    }

    if ($created > 0) {
      $this->messenger()->addMessage($is_export
        ? $this->t("@count delivery notes have been created.", ["@count" => $created])
        : $this->t("@count goods receipt forms have been created.", ["@count" => $created])
      );
    }

    if (!empty($empty_lines)) {
      $this->messenger()->addWarning(
        $this->t("No goods were selected for this invoice.") . " " . implode(", ", $empty_lines)
      );
    }

    if (!empty($no_contact)) {
      $this->messenger()->addError(
        $this->t("Please select a valid supplier.") . " " . implode(", ", $no_contact)
      );
    }

    if (!empty($failed)) {
      $this->messenger()->addError(($is_export
        ? $this->t("Invoice issuance failed.")
        : $this->t("Invoice entry failed.")) . " " . implode(", ", $failed)
      );
    }
  }

  /**
   * Hủy tạo phiếu nhập/xuất hàng cho mọi hóa đơn đang chọn.
   */
  public function submitClose(array &$form, FormStateInterface $form_state) {
    $pending = $form_state->get("pending") ?: [];
    $handle_field = $form_state->get("handle_field");

    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    foreach ($pending as $invoice) {
      $invoice->set($handle_field, self::ITEM_SKIPPED);
      $invoice->save();
    }

    $this->messenger()->addMessage($this->t("Invoice cancelled successfully."));
    $this->redirectBack($form_state, (bool) $form_state->get("is_export"));
  }

  /**
   * Đối tác của từng tên trên hóa đơn sau khi kế toán đối chiếu.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return array
   *   Tên đối tác trên hóa đơn ánh xạ sang id khách hàng / nhà cung cấp.
   */
  private function resolveContacts(FormStateInterface $form_state): array {
    $contacts = $form_state->get("contacts") ?: [];
    $values = $form_state->getValue("invoice_supplier") ?: [];
    $ids = [];

    foreach ($contacts as $key => $contact) {
      if (!empty($contact["id"])) {
        $ids[$contact["name"]] = $contact["id"];
        continue;
      }

      $value = $values[$key] ?? [];

      switch ($value["action"] ?? "") {
        case "select":
          $contact_id = $value["contact_wrapper"] ?? NULL;

          if (empty($contact_id)) {
            break;
          }

          $this->invoiceService->createAliasName(
            $contact_id,
            $contact["name"],
            "crm",
            "field_customer_alias_names"
          );

          $ids[$contact["name"]] = $contact_id;
          break;

        case "create":
          if ($contact["name"] === "") {
            break;
          }

          $ids[$contact["name"]] = $this->invoiceService->createSupplier(
            $contact["name"],
            $contact["taxcode"],
            $contact["company"]
          );
          break;
      }
    }

    return $ids;
  }

  /**
   * Vật tư của từng tên hàng sau khi kế toán đối chiếu.
   *
   * Mỗi tên hàng chỉ tạo vật tư / gắn tên gọi khác một lần dù nằm ở bao nhiêu
   * hóa đơn.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param array $units
   *   Bản đồ tên đơn vị tính sang term id.
   *
   * @return array
   *   Tên hàng ánh xạ sang ["id" => vật tư, "exist" => trạng thái dòng].
   */
  private function resolveItems(FormStateInterface $form_state, array $units): array {
    $items = $form_state->get("items") ?: [];
    $values = $form_state->getValue("invoice_item") ?: [];
    $map = [];

    foreach ($items as $key => $item) {
      if (!empty($item["id"])) {
        $map[$item["name"]] = [
          "id" => $item["id"],
          "exist" => self::ITEM_MATCHED,
        ];
        continue;
      }

      $value = $values[$key] ?? [];

      switch ($value["action"] ?? "none") {
        case "select":
          preg_match('/\((\d+)\)/', $value["product_wrapper"]["exit_product"] ?? "", $matches);

          $supply_id = !empty($matches[1])
            ? $this->invoiceService->createAliasName($matches[1], $item["name"])
            : NULL;

          $map[$item["name"]] = [
            "id" => $supply_id,
            "exist" => $supply_id ? self::ITEM_MATCHED : 0,
          ];
          break;

        case "create":
          $unit = !empty($item["unit"]) ? mb_strtolower($item["unit"], "UTF-8") : NULL;

          $map[$item["name"]] = [
            "id" => $this->invoiceService->createSupplies(
              $value["product_wrapper"]["new_product"]["code_product"],
              $item["name"],
              $units[$unit] ?? NULL,
              $value["product_wrapper"]["new_product"]["type_product"],
              $item["company"]
            ),
            "exist" => self::ITEM_MATCHED,
          ];
          break;

        default:
          $map[$item["name"]] = [
            "id" => NULL,
            "exist" => self::ITEM_SKIPPED,
          ];
          break;
      }
    }

    return $map;
  }

  /**
   * Gom đối tác của các hóa đơn theo tên và tìm sẵn bên đã có trong hệ thống.
   *
   * @param array $invoices
   *   Hóa đơn đang xử lý.
   * @param bool $is_export
   *   TRUE khi đang xử lý hóa đơn đầu ra.
   *
   * @return array
   *   Danh sách đối tác đánh số theo dòng của bảng.
   */
  private function collectContacts(array $invoices, bool $is_export): array {
    $contacts = [];

    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    foreach ($invoices as $invoice) {
      // Hóa đơn không ghi tên đối tác vẫn phải hiện một dòng, nếu không kế
      // toán không có chỗ nào để chỉ ra bên mua / bên bán.
      $name = $this->contactName($invoice, $is_export);

      if (!isset($contacts[$name])) {
        $contacts[$name] = [
          "name" => $name,
          "taxcode" => (string) ($is_export
            ? $invoice->get("field_invoice_buyer_taxcode")->value
            : $invoice->get("field_invoice_seller_taxcode")->value),
          "company" => $invoice->get("field_invoice_company")->target_id,
          "invoices" => [],
          "id" => NULL,
        ];
      }

      $contacts[$name]["invoices"][] = $invoice->get("field_invoice_no")->value ?: $invoice->id();
    }

    ksort($contacts);

    $found = $this->invoiceService->findSuppliers(array_keys($contacts));

    foreach ($contacts as $name => $contact) {
      $contacts[$name]["id"] = $found[$name] ?? NULL;
    }

    return array_values($contacts);
  }

  /**
   * Gom dòng hàng của các hóa đơn theo tên và tìm sẵn vật tư tương ứng.
   *
   * @param array $invoices
   *   Hóa đơn đang xử lý.
   *
   * @return array
   *   Danh sách tên hàng đánh số theo dòng của bảng.
   */
  private function collectItems(array $invoices): array {
    $items = [];

    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    foreach ($invoices as $invoice) {
      $company = $invoice->get("field_invoice_company")->target_id;

      foreach ($invoice->get("field_invoice_items")->getValue() as $line) {
        $name = (string) ($line["item_name"] ?? "");

        if ($name === "") {
          continue;
        }

        $price = (float) ($line["item_price"] ?? 0);

        if (!isset($items[$name])) {
          $items[$name] = [
            "name" => $name,
            "unit" => (string) ($line["item_unit"] ?? ""),
            "company" => $company,
            "invoices" => [],
            "min_price" => $price,
            "max_price" => $price,
            // Hóa đơn mới kéo về chưa có khoá này nên phải dùng mặc định.
            "id" => (int) ($line["item_exist"] ?? 0) === self::ITEM_MATCHED
              ? ($line["item_id"] ?? NULL)
              : NULL,
          ];
        }

        $items[$name]["invoices"][$invoice->id()] = $invoice->id();
        $items[$name]["min_price"] = min($items[$name]["min_price"], $price);
        $items[$name]["max_price"] = max($items[$name]["max_price"], $price);
      }
    }

    ksort($items);

    // Tên gọi khác của vật tư cũng được nhận, tra một lần cho mọi tên hàng.
    $supplies = $this->invoiceService->findSupplies(array_keys($items));

    foreach ($items as $name => $item) {
      if (isset($supplies[$name])) {
        $items[$name]["id"] = $supplies[$name];
      }
    }

    return array_values($items);
  }

  /**
   * Hóa đơn theo danh sách uuid gửi lên.
   *
   * Uuid đến từ tham số đường dẫn (một hóa đơn mở trong modal) hoặc từ ô ẩn
   * "uuids" khi kế toán chọn nhiều hóa đơn ngoài danh sách.
   *
   * @param string|null $uuid
   *   Tham số uuid của đường dẫn.
   *
   * @return array
   *   Danh sách hóa đơn đã tải.
   */
  private function loadInvoices(?string $uuid): array {
    $request = $this->getRequest();

    $raw = $uuid ?: $request->request->get("uuids") ?: $request->query->get("uuids") ?: "";

    $uuids = array_values(array_filter(array_map("trim", explode(",", (string) $raw))));

    if (empty($uuids)) {
      throw new NotFoundHttpException();
    }

    $invoices = $this->entityTypeManager
      ->getStorage("invoice")
      ->loadByProperties([
        "uuid" => $uuids,
      ]);

    if (empty($invoices)) {
      throw new NotFoundHttpException();
    }

    return $invoices;
  }

  /**
   * Tên đối tác trên hóa đơn.
   *
   * @param \Drupal\e_invoice\InvoiceInterface $invoice
   *   Hóa đơn cần đọc.
   * @param bool $is_export
   *   TRUE khi đang xử lý hóa đơn đầu ra.
   */
  private function contactName($invoice, bool $is_export): string {
    return (string) ($is_export
      ? $invoice->get("field_invoice_buyer_name")->value
      : $invoice->get("field_invoice_seller_name")->value);
  }

  /**
   * Giá của tên hàng, hiện khoảng giá khi các hóa đơn ghi khác nhau.
   *
   * @param array $item
   *   Dòng hàng đã gom theo tên.
   */
  private function priceLabel(array $item): string {
    $min = number_format($item["min_price"], 0, ",", ".");

    if ($item["min_price"] === $item["max_price"]) {
      return $min;
    }

    return $min . " - " . number_format($item["max_price"], 0, ",", ".");
  }

  /**
   * Đường dẫn danh sách hóa đơn tương ứng.
   *
   * @param bool $is_export
   *   TRUE khi đang xử lý hóa đơn đầu ra.
   */
  private function listUrl(bool $is_export) {
    return Url::fromRoute($is_export
      ? "erp_e_invoice.e_invoice_list_out"
      : "erp_e_invoice.e_invoice_list_in");
  }

  /**
   * Quay lại danh sách hóa đơn kế toán vừa thao tác.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param bool $is_export
   *   TRUE khi đang xử lý hóa đơn đầu ra.
   */
  private function redirectBack(FormStateInterface $form_state, bool $is_export): void {
    $url = $this->invoiceService->safeRedirect(
      $this->destination(),
      $is_export ? "erp_e_invoice.e_invoice_list_out" : "erp_e_invoice.e_invoice_list_in"
    );

    $form_state->setResponse(new RedirectResponse($url));
  }

  /**
   * Đường dẫn quay lại do danh sách hóa đơn gửi kèm.
   */
  private function destination(): string {
    $request = $this->getRequest();

    return (string) ($request->request->get("destination")
      ?? $request->query->get("destination")
      ?? "");
  }

}

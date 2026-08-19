<?php

namespace Drupal\erp_e_invoice\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\erp_e_invoice\InvoiceService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Đối chiếu dòng hóa đơn với vật tư rồi tạo phiếu nhập/xuất kho.
 */
class UpdateInvoiceItemsForm extends FormBase {

  /**
   * Constructs an UpdateInvoiceItemsForm object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param InvoiceService $invoiceService
   *   The invoice service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InvoiceService $invoiceService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get("entity_type.manager"),
      $container->get("erp_e_invoice.invoice_service"),
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
    $invoices = $this->entityTypeManager
      ->getStorage("invoice")
      ->loadByProperties([
        "uuid" => $uuid,
      ]);

    /** @var \Drupal\e_invoice\Entity\Invoice $invoice */
    $invoice = reset($invoices);

    if (!$invoice) {
      throw new NotFoundHttpException();
    }

    $invoice_bundle = $invoice->bundle();

    $contact_name = $invoice_bundle === "output_invoices"
      ? $invoice->get("field_invoice_buyer_name")->value
      : $invoice->get("field_invoice_seller_name")->value;

    $list_items = $invoice->get("field_invoice_items")->getValue();
    $itemNames = array_column($list_items, "item_name");

    // Tìm thông tin.
    $dataSupplies = $this->invoiceService->findSupplies($itemNames);
    $supplier_id = $this->invoiceService->findSupplier($contact_name);
    $type_list = $this->invoiceService->loadTerm("supplies_type", "id");

    $form_state->set("invoice", $invoice);
    $form_state->set("supplier_id", $supplier_id);
    $form_state->set("company_id", $invoice->get("field_invoice_company")->target_id);

    $form = [
      "invoice" => [
        "#markup" => "<h3>" . $invoice->label() . "</h3>",
      ],
    ];

    $form["invoice_supplier"] = [
      "#type" => "container",
      "#attributes" => [
        "class" => ["d-flex gap-2 py-3"],
      ],

      "supplier_name" => [
        "#markup" => "<p class='col-6 mb-0 fw-bold'>" . $contact_name . "</p>",
      ],
    ];

    if (empty($supplier_id)) {
      $form["invoice_supplier"]["supplier_select"] = [
        "#type" => "select",
        "#attributes" => [
          "class" => ["mx-auto"],
        ],
        "#options" => [
          "select" => $this->t("Available"),
          "create" => $this->t("Create new"),
        ],
      ];

      $form["invoice_supplier"]["supplier_form"] = [
        "#type" => "entity_autocomplete",
        "#placeholder" => $this->t("Search for supplier"),
        "#target_type" => "crm",
        "#selection_settings" => [
          "target_bundles" => ["customers"],
        ],
        "#states" => [
          "visible" => [
            ':input[name="supplier_select"]' => ['value' => 'select'],
          ],
          "required" => [
            ':input[name="supplier_select"]' => ['value' => 'select'],
          ],
        ],
      ];
    }

    $form["invoice_item"] = [
      "#type" => "table",
      "#tree" => TRUE,
      "#title" => $this->t("Templates"),
      "#header" => [
        [
          "data" => $this->t("Name"),
        ],
        [
          "data" => $this->t("Unit"),
          "class" => ["w-15", "text-center"],
        ],
        [
          "data" => $this->t("Price"),
          "class" => ["w-15", "text-center"],
        ],
        [
          "data" => $this->t("Select"),
          "class" => ["w-15", "text-center"],
        ],
        [
          "data" => $this->t("Action"),
          "class" => ["w-25", "text-center"],
        ],
        [
          "data" => "",
        ],
      ],
    ];

    foreach ($list_items as $key => $item) {
      // Hóa đơn mới nhập chưa có 2 khoá này nên phải dùng giá trị mặc định.
      $item_id = $item["item_id"] ?? NULL;
      $item_exist = $item["item_exist"] ?? 0;

      if (isset($dataSupplies[$item["item_name"]])) {
        $item_id = $dataSupplies[$item["item_name"]];
        $item_exist = 1;
      }

      $form["invoice_item"][$key] = [
        "name" => [
          "#markup" => $item["item_name"],
        ],
        "unit" => [
          "#markup" => "<p class='mb-0 text-center'>" . $item["item_unit"] . "</p>",
        ],
        "price" => [
          "#markup" => "<p class='mb-0 text-center'>" . number_format($item["item_price"], 0, ",", ".") . "</p>",
        ],
      ];

      if ($item_exist == 1) {
        $form["invoice_item"][$key]["action"] = [
          "#type" => "hidden",
          "#value" => "exist",
        ];

        $form["invoice_item"][$key]["product_wrapper"] = [
          "#markup" => "<p class='mb-0 text-center'>" . $this->t("Existing product") . "</p>",
        ];
      }
      else {
        $form["invoice_item"][$key]["action"] = [
          "#type" => "select",
          "#attributes" => [
            "class" => ["mx-auto"],
          ],
          "#options" => [
            "select" => $this->t("Available"),
            "create" => $this->t("Create new"),
            "none" => $this->t("Unimport"),
          ],
        ];

        $form["invoice_item"][$key]["product_wrapper"] = [
          "#type" => "container",
        ];

        $form["invoice_item"][$key]["product_wrapper"]["exit_product"] = [
          "#type" => "textfield",
          "#placeholder" => $this->t("Search for goods and services"),
          "#autocomplete_route_name" => "entity_reference_modal.autocomplete",
          "#autocomplete_route_parameters" => [
            "selection_settings_key" => $this->invoiceService->getAutocompleteSettingsKey(),
          ],
          "#attributes" => [
            "class" => ["input-search-product"],
          ],
          "#attached" => [
            "library" => ["erp_e_invoice/e_invoice_update"],
          ],
          "#states" => [
            "visible" => [
              ':input[name="invoice_item[' . $key . '][action]"]' => ['value' => 'select'],
            ],
            "required" => [
              ':input[name="invoice_item[' . $key . '][action]"]' => ['value' => 'select'],
            ],
          ],
        ];

        $form["invoice_item"][$key]["product_wrapper"]["new_product"] = [
          "#type" => "container",
          "#attributes" => [
            "class" => ["d-flex gap-2"],
          ],

          "code_product" => [
            "#type" => "textfield",
            "#placeholder" => $this->t("Internal code"),
            "#states" => [
              "visible" => [
                ':input[name="invoice_item[' . $key . '][action]"]' => ['value' => 'create'],
              ],
              "required" => [
                ':input[name="invoice_item[' . $key . '][action]"]' => ['value' => 'create'],
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
                ':input[name="invoice_item[' . $key . '][action]"]' => ['value' => 'create'],
              ],
              "required" => [
                ':input[name="invoice_item[' . $key . '][action]"]' => ['value' => 'create'],
              ],
            ],
          ],
        ];
      }

      $form["invoice_item"][$key]["item_info"] = [
        "id" => [
          "#type" => "value",
          "#value" => $item_id ?? "",
        ],
        "name" => [
          "#type" => "value",
          "#value" => $item["item_name"],
        ],
        "unit" => [
          "#type" => "value",
          "#value" => $item["item_unit"],
        ],
      ];
    }

    $form["submit"] = [
      "#type" => "submit",
      "#value" => $invoice_bundle === "output_invoices" ? $this->t("Create export") : $this->t("Create import"),
      "#attributes" => [
        "class" => ["btn-success"],
      ],
    ];

    $form["close"] = [
      "#type" => "submit",
      "#submit" => ["::submitClose"],
      "#limit_validation_errors" => [],
      "#value" => $invoice_bundle === "output_invoices" ? $this->t("Not export") : $this->t("Not import"),
      "#attributes" => [
        "class" => ["btn-danger"],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $data = $form_state->getValue("invoice_item") ?: [];
    $new_codes = [];

    // Chưa tìm được đối tác mà kế toán chọn "Có sẵn" thì bắt buộc phải chỉ ra
    // đối tác — #states chỉ ràng buộc phía trình duyệt.
    if (empty($form_state->get("supplier_id"))
      && $form_state->getValue("supplier_select") === "select"
      && empty($form_state->getValue("supplier_form"))
    ) {
      $form_state->setErrorByName("supplier_form", $this->t("Please select a valid supplier."));
    }

    foreach ($data as $key => $value) {
      if (($value["action"] ?? "") === "select") {
        $product = $value["product_wrapper"]["exit_product"] ?? "";
        if (empty($product) || !preg_match("/\(\d+\)$/", $product)) {
          $form_state->setErrorByName(
            "invoice_item][$key][product_wrapper][exit_product",
            $this->t("Please select a valid product.")
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\e_invoice\InvoiceInterface $invoice */
    $invoice = $form_state->get("invoice");
    $supplier_id = $form_state->get("supplier_id");
    $company_id = $form_state->get("company_id");
    $data = $form_state->getValue("invoice_item") ?: [];

    $is_export = $invoice->bundle() === "output_invoices";
    $handle_field = $is_export ? "field_invoice_export" : "field_invoice_import";
    $redirect = $is_export
      ? "erp_e_invoice.e_invoice_list_out"
      : "erp_e_invoice.e_invoice_list_in";

    $form_state->setRedirect($redirect);

    // Hóa đơn đã xử lý rồi (gửi lại form, bấm hai lần, mở lại link cũ) thì
    // dừng ở đây, nếu không sẽ tạo phiếu và cộng tồn kho lần thứ hai.
    if ($this->invoiceService->isHandled($invoice, $handle_field)) {
      $this->messenger()->addWarning($this->t("This invoice has already been processed."));
      return;
    }

    $list_items = $invoice->get("field_invoice_items")->getValue();
    $units = $this->invoiceService->loadTerm();

    // Kiểm tra nhà cung cấp.
    if (empty($supplier_id)) {
      $select_supplier = $form_state->getValue("supplier_select");

      $contact_name = $is_export
        ? $invoice->get("field_invoice_buyer_name")->value
        : $invoice->get("field_invoice_seller_name")->value;

      $contact_taxcode = $is_export
        ? $invoice->get("field_invoice_buyer_taxcode")->value
        : $invoice->get("field_invoice_seller_taxcode")->value;

      switch ($select_supplier) {
        case "select":
          $supplier_id = $form_state->getValue("supplier_form");
          $this->invoiceService->createAliasName($supplier_id, $contact_name, "crm", "field_customer_alias_names");
          break;

        case "create":
          $supplier_id = $this->invoiceService->createSupplier($contact_name, $contact_taxcode, $company_id);
          break;
      }
    }

    if (empty($supplier_id)) {
      $this->messenger()->addError($this->t("Please select a valid supplier."));
      return;
    }

    // Kiểm tra vật tư.
    foreach ($data as $key => $value) {
      $item_info = $value["item_info"];
      $action = $value["action"];

      switch ($action) {
        case "exist":
          $list_items[$key]["item_exist"] = 1;
          $list_items[$key]["item_id"] = $item_info["id"];
          break;

        case "none":
          $list_items[$key]["item_exist"] = 2;
          $list_items[$key]["item_id"] = NULL;
          break;

        case "select":
          preg_match('/\((\d+)\)/', $value["product_wrapper"]["exit_product"], $matches);
          $supply_id = $this->invoiceService->createAliasName($matches[1], $item_info["name"]);

          $list_items[$key]["item_exist"] = $supply_id ? 1 : 0;
          $list_items[$key]["item_id"] = $supply_id;
          break;

        case "create":
          $unit = !empty($item_info["unit"]) ? mb_strtolower($item_info["unit"], "UTF-8") : NULL;
          $list_items[$key]["item_id"] = $this->invoiceService->createSupplies(
            $value["product_wrapper"]["new_product"]["code_product"],
            $item_info["name"],
            $units[$unit] ?? NULL,
            $value["product_wrapper"]["new_product"]["type_product"],
            $company_id
          );
          $list_items[$key]["item_exist"] = 1;
          break;
      }
    }

    // Không còn dòng nào để ghi kho (kế toán chọn không nhập/xuất hết) thì
    // đóng hóa đơn luôn thay vì báo lỗi tạo phiếu.
    $has_line = FALSE;
    foreach ($list_items as $item) {
      if (!empty($item["item_id"]) && (int) ($item["item_exist"] ?? 0) === 1) {
        $has_line = TRUE;
        break;
      }
    }

    // Cập nhật lại thông tin hóa đơn.
    $invoice->set("field_invoice_items", $list_items);

    if (!$has_line) {
      $invoice->set($handle_field, 2);
    }

    $invoice->save();

    if (!$has_line) {
      $this->messenger()->addWarning($this->t("No goods were selected for this invoice."));
      return;
    }

    // Lấy kho hàng
    $warehouse_id = $this->invoiceService->findWarehouse();
    if (empty($warehouse_id)) {
      $this->messenger()->addError($this->t("Not found e-invoice warehouse"));
      return;
    }

    $created = $is_export
      ? $this->invoiceService->createExport($invoice, $supplier_id, $warehouse_id, $units)
      : $this->invoiceService->createImport($invoice, $supplier_id, $warehouse_id, $units);

    // createExport/createImport nuốt ngoại lệ và trả FALSE, phải đọc kết quả
    // trả về mới biết phiếu có thực sự được tạo hay không.
    if (!$created) {
      $this->messenger()->addError(
        $is_export
          ? $this->t("Invoice issuance failed.")
          : $this->t("Invoice entry failed.")
      );
      return;
    }

    $this->messenger()->addMessage(
      $is_export
        ? $this->t("The delivery note has been created.")
        : $this->t("The goods receipt form has been created.")
    );
  }

  /**
   * Hủy tạo phiếu nhập/xuất hàng từ hóa đơn.
   */
  public function submitClose(array &$form, FormStateInterface $form_state) {
    $invoice = $form_state->get("invoice");

    $redirect = $invoice->bundle() === "output_invoices"
      ? "erp_e_invoice.e_invoice_list_out"
      : "erp_e_invoice.e_invoice_list_in";

    $field = $invoice->bundle() === "output_invoices" ? "field_invoice_export" : "field_invoice_import";

    $invoice->set($field, 2);
    $invoice->save();

    $this->messenger()->addMessage($this->t("Invoice cancelled successfully."));

    $form_state->setRedirect($redirect);
  }

}

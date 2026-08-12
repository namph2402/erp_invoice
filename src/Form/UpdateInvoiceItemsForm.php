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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\erp_e_invoice\InvoiceService $invoiceService
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
        "#type" => "hidden",
        "id" => [
          "#type" => "hidden",
          "#value" => $item_id ?? "",
        ],
        "name" => [
          "#type" => "hidden",
          "#value" => $item["item_name"],
        ],
        "unit" => [
          "#type" => "hidden",
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
    foreach ($data as $key => $value) {
      if ($value["action"] === "select") {
        $product = $value["product_wrapper"]["exit_product"];
        if (empty($product) || !preg_match("/\(\d+\)$/", $product)) {
          $form_state->setErrorByName(
            "invoice_item][$key][product_wrapper][exit_product",
            $this->t("Please select a valid product.")
          );
        }
      }
      elseif ($value["action"] === "create") {
        $code = $value["product_wrapper"]["new_product"]["code_product"];
        $count = $this->entityTypeManager
          ->getStorage("supplies")
          ->getQuery()
          ->condition("field_sup_code", $code)
          ->accessCheck(TRUE)
          ->count()
          ->execute();

        if ($count > 0) {
          $form_state->setErrorByName(
            "invoice_item][$key][product_wrapper][exit_product",
            $this->t("Internal code already exists.") . " " . $code
          );
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $invoice = $form_state->get("invoice");
    $supplier_id = $form_state->get("supplier_id");
    $company_id = $form_state->get("company_id");
    $data = $form_state->getValue("invoice_item") ?: [];

    $list_items = $invoice->get("field_invoice_items")->getValue();
    $units = $this->invoiceService->loadTerm();

    $check_handle = $invoice->bundle() === "output_invoices"
      ? $invoice->get("field_invoice_export")->value
      : $invoice->get("field_invoice_import")->value;

    $redirect = $invoice->bundle() === "output_invoices"
      ? "erp_e_invoice.e_invoice_list_out"
      : "erp_e_invoice.e_invoice_list_in";

    if (in_array($check_handle, [1, 2], TRUE)) {
      $form_state->setRedirect($redirect);
      return;
    }

    // Kiểm tra nhà cung cấp.
    if (empty($supplier_id)) {
      $select_supplier = $form_state->getValue("supplier_select");

      $contact_name = $invoice->bundle() === "output_invoices"
        ? $invoice->get("field_invoice_buyer_name")->value
        : $invoice->get("field_invoice_seller_name")->value;

      $contact_taxcode = $invoice->bundle() === "output_invoices"
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
          break;

        case "select":
          preg_match('/\((\d+)\)/', $value["product_wrapper"]["exit_product"], $matches);
          $list_items[$key]["item_exist"] = 1;
          $list_items[$key]["item_id"] = $this->invoiceService->createAliasName($matches[1], $item_info["name"]);
          break;

        case "create":
          $unit = !empty($item_info["unit"]) ? mb_strtolower($item_info["unit"], "UTF-8") : NULL;
          $unit_id = $units[$unit] ?? NULL;
          $id = $this->invoiceService->createSupplies(
            $value["product_wrapper"]["new_product"]["code_product"],
            $item_info["name"],
            $unit_id,
            $value["product_wrapper"]["new_product"]["type_product"],
            $company_id
          );
          $list_items[$key]["item_id"] = $id;
          $list_items[$key]["item_exist"] = 1;
          break;
      }
    }

    // Cập nhật lại thông tin hóa đơn.
    $invoice->set("field_invoice_items", $list_items);
    $invoice->save();

    // Lấy kho hàng
    $warehouse_id = $this->invoiceService->findWarehouse();
    if (empty($warehouse_id)) {
      $this->messenger()->addError($this->t("Not found e-invoice warehouse"));
      $form_state->setRedirect($redirect);
      return;
    }

    try {
      $invoice->bundle() === "output_invoices"
        ? $this->invoiceService->createExport($invoice, $supplier_id, $warehouse_id, $units)
        : $this->invoiceService->createImport($invoice, $supplier_id, $warehouse_id, $units);
      $this->messenger()->addMessage(
        $invoice->bundle() === "output_invoices"
          ? $this->t("The delivery note has been created.")
          : $this->t("The goods receipt form has been created.")
      );
      $form_state->setRedirect($redirect);
    }
    catch (\Exception $e) {
      $this->logger("erp_e_invoice")->error($e->getMessage());
      $this->messenger()->addError(
        $invoice->bundle() === "output_invoices"
          ? $this->t("Invoice issuance failed.")
          : $this->t("Invoice entry failed.")
      );
    }
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

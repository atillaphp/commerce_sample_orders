<?php

namespace Drupal\commerce_sample_orders\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_price\Price;
use Drupal\physical\Weight;

/**
 * Provides a development helper form to generate sample commerce orders.
 */
class GenerateSampleOrdersForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_sample_orders_generator_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $stores = Store::loadMultiple();
    if (empty($stores)) {
      $form['message'] = [
        '#markup' => '<div class="messages messages--error">' . $this->t('No commerce stores found. Please create a store first.') . '</div>',
      ];
      return $form;
    }

    $store_options = [];
    foreach ($stores as $store) {
      $store_options[$store->id()] = $store->label();
    }

    $variations = ProductVariation::loadMultiple();
    if (empty($variations)) {
      $form['message'] = [
        '#markup' => '<div class="messages messages--warning">' . $this->t('No product variations found in the system. Please sync or create some products first.') . '</div>',
      ];
      return $form;
    }

    $form['settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Order Generation Settings'),
      '#open' => TRUE,
    ];

    $form['settings']['store_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Target Store'),
      '#options' => $store_options,
      '#required' => TRUE,
      '#default_value' => array_key_first($store_options),
    ];

    $form['settings']['num_orders'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Orders to Generate'),
      '#min' => 1,
      '#max' => 100,
      '#default_value' => 10,
      '#required' => TRUE,
    ];

    $form['settings']['max_products'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Products per Order'),
      '#min' => 1,
      '#max' => 10,
      '#default_value' => 3,
      '#required' => TRUE,
    ];

    $form['settings']['order_state'] = [
      '#type' => 'select',
      '#title' => $this->t('Order State'),
      '#options' => [
        'draft' => 'Draft',
        'fulfillment' => 'Fulfillment (Placed)',
        'completed' => 'Completed',
      ],
      '#default_value' => 'fulfillment',
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Orders'),
      '#button_type' => 'primary',
    ];

    $generated_ids = \Drupal::state()->get('commerce_sample_orders.generated_ids', []);
    if (!empty($generated_ids)) {
      $form['actions']['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete All Sample Orders (@count)', ['@count' => count($generated_ids)]),
        '#button_type' => 'danger',
        '#submit' => ['::deleteSampleOrders'],
        '#limit_validation_errors' => [],
        '#attributes' => [
          'class' => ['button--danger'],
          'style' => 'background-color: #e63946; color: white; border: none;',
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $store_id = $form_state->getValue('store_id');
    $num_orders = (int) $form_state->getValue('num_orders');
    $max_products = (int) $form_state->getValue('max_products');
    $order_state = $form_state->getValue('order_state');

    $variations = ProductVariation::loadMultiple();
    if (empty($variations)) {
      $this->messenger()->addError($this->t('No product variations found. Cannot generate orders.'));
      return;
    }

    $generated_ids = \Drupal::state()->get('commerce_sample_orders.generated_ids', []);
    $new_ids = [];
    $variation_keys = array_keys($variations);
    $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');

    for ($i = 0; $i < $num_orders; $i++) {
      // Pick a random number of products between 1 and $max_products
      $num_items = rand(1, $max_products);

      // Generate a realistic random billing address and customer email
      $billing_data = $this->getRandomAddress();
      $email_prefix = strtolower(str_replace(
        ['ı', 'ö', 'ü', 'ş', 'ğ', 'ç', ' '],
        ['i', 'o', 'u', 's', 'g', 'c', '.'],
        $billing_data['given_name'] . '.' . $billing_data['family_name']
      ));
      $customer_email = $email_prefix . rand(10, 99) . '@example.com';
      
      // Create the order
      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order = Order::create([
        'type' => 'default',
        'store_id' => $store_id,
        'uid' => 0, // Guest checkout
        'mail' => $customer_email,
        'ip_address' => '127.0.0.1',
        'state' => $order_state,
        'placed' => \Drupal::time()->getRequestTime(),
      ]);
      $order->save();

      // Set the order number to match the order ID so the ID column is not empty
      $order->setOrderNumber($order->id());
      $order->save();

      // Create and associate billing profile
      $billing_profile = $profile_storage->create([
        'type' => 'customer',
        'uid' => 0, // Guest checkout
        'address' => $billing_data,
      ]);
      $billing_profile->save();
      $order->setBillingProfile($billing_profile);

      // Randomly select items
      $keys = (array) array_rand($variation_keys, min($num_items, count($variation_keys)));
      $order_items = [];
      
      foreach ($keys as $k) {
        $var_id = $variation_keys[$k];
        /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
        $variation = $variations[$var_id];
        $qty = rand(1, 3);

        $order_item = OrderItem::create([
          'type' => 'default',
          'purchased_entity' => $variation->id(),
          'quantity' => $qty,
          'unit_price' => $variation->getPrice(),
          'order_id' => $order->id(),
          'title' => $variation->getOrderItemTitle() ?: $variation->label(),
        ]);
        $order_item->save();
        $order->addItem($order_item);
        $order_items[] = $order_item;
      }

      // If commerce shipping is installed and enabled, create a shipping profile and shipment
      if ($order->hasField('shipments') && \Drupal::entityTypeManager()->hasDefinition('commerce_shipment') && !empty($order_items)) {
        $shipping_data = $this->getRandomAddress();
        // Match the shipping customer name to billing customer name, but select a different address
        $shipping_data['given_name'] = $billing_data['given_name'];
        $shipping_data['family_name'] = $billing_data['family_name'];

        $shipping_profile = $profile_storage->create([
          'type' => 'customer',
          'uid' => 0, // Guest checkout
          'address' => $shipping_data,
        ]);
        $shipping_profile->save();

        // Determine correct shipment type
        $shipment_type_id = 'default';
        $order_type = \Drupal::entityTypeManager()->getStorage('commerce_order_type')->load($order->bundle());
        if ($order_type) {
          $shipment_type_id = (string) ($order_type->getThirdPartySetting('commerce_shipping', 'shipment_type', 'default') ?: 'default');
        }

        // Build shipment items
        $shipment_items = [];
        foreach ($order_items as $order_item) {
          $unit_price = $order_item->getUnitPrice();
          $declared_value = new Price(
            (string) (($unit_price ? (float) $unit_price->getNumber() : 0.0) * (float) $order_item->getQuantity()),
            $unit_price?->getCurrencyCode() ?: 'TRY'
          );
          $shipment_items[] = new \Drupal\commerce_shipping\ShipmentItem([
            'order_item_id' => (int) $order_item->id(),
            'title' => (string) ($order_item->getTitle() ?: 'Sample product'),
            'quantity' => (string) $order_item->getQuantity(),
            'weight' => new Weight('0', 'kg'),
            'declared_value' => $declared_value,
          ]);
        }

        $shipment = \Drupal::entityTypeManager()->getStorage('commerce_shipment')->create([
          'type' => $shipment_type_id,
          'order_id' => (int) $order->id(),
          'title' => (string) $this->t('Sample shipment'),
          'shipping_profile' => $shipping_profile,
          'items' => $shipment_items,
          'amount' => new Price('0.00', $order->getStore()->getDefaultCurrencyCode()),
        ]);
        $shipment->save();
        $order->set('shipments', [$shipment]);
      }

      $order->save();
      $new_ids[] = $order->id();
    }

    $all_ids = array_merge($generated_ids, $new_ids);
    \Drupal::state()->set('commerce_sample_orders.generated_ids', $all_ids);

    $this->messenger()->addStatus($this->t('Successfully generated @count sample orders with proper customer, billing, and shipping details.', ['@count' => count($new_ids)]));
  }

  /**
   * Submit handler for deleting all generated sample orders.
   */
  public function deleteSampleOrders(array &$form, FormStateInterface $form_state) {
    $generated_ids = \Drupal::state()->get('commerce_sample_orders.generated_ids', []);
    if (empty($generated_ids)) {
      $this->messenger()->addWarning($this->t('No generated sample orders found to delete.'));
      return;
    }

    $order_storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
    $shipment_storage = \Drupal::entityTypeManager()->hasDefinition('commerce_shipment')
      ? \Drupal::entityTypeManager()->getStorage('commerce_shipment')
      : NULL;

    $orders = $order_storage->loadMultiple($generated_ids);
    
    $deleted_count = 0;
    if (!empty($orders)) {
      foreach ($orders as $order) {
        $profiles_to_delete = [];

        // Collect billing profile
        if ($billing_profile = $order->getBillingProfile()) {
          $profiles_to_delete[] = $billing_profile;
        }

        // Collect shipping profile and delete shipments
        if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
          foreach ($order->get('shipments')->referencedEntities() as $shipment) {
            if ($shipping_profile = $shipment->getShippingProfile()) {
              $profiles_to_delete[] = $shipping_profile;
            }
            if ($shipment_storage) {
              $shipment->delete();
            }
          }
        }

        // Delete order itself
        $order->delete();

        // Delete associated profiles
        if (!empty($profiles_to_delete)) {
          $profile_storage->delete($profiles_to_delete);
        }

        $deleted_count++;
      }
    }

    \Drupal::state()->set('commerce_sample_orders.generated_ids', []);
    $this->messenger()->addStatus($this->t('Successfully deleted @count sample orders and all associated billing, shipping, and shipment profiles.', ['@count' => $deleted_count]));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Generates a realistic random customer profile (address array).
   */
  private function getRandomAddress() {
    $first_names = [
      'Ahmet', 'Mehmet', 'Mustafa', 'Ali', 'Hüseyin', 'Hasan', 'İbrahim', 'Halil', 'Yusuf', 'Ömer',
      'Zeynep', 'Elif', 'Defne', 'Ecrin', 'Yağmur', 'Azra', 'Nisanur', 'Merve', 'Fatma', 'Emine',
      'Can', 'Cem', 'Deniz', 'Ege', 'Mert', 'Murat', 'Burak', 'Volkan', 'Selim', 'Selin', 'Dilan'
    ];
    $last_names = [
      'Yılmaz', 'Kaya', 'Demir', 'Şahin', 'Çelik', 'Yıldız', 'Yıldırım', 'Öztürk', 'Aydın', 'Özdemir',
      'Arslan', 'Doğan', 'Kılıç', 'Aslan', 'Çetin', 'Kara', 'Koç', 'Kurt', 'Özkan', 'Şimşek'
    ];
    $cities = [
      '34' => [
        'name' => 'İstanbul',
        'districts' => [
          'Kadıköy' => ['Rasimpaşa Mah. Yoğurtçu Şükrü Sok. No: 12', 'Caferağa Mah. Moda Cad. No: 45', 'Acıbadem Mah. Saray Sok. No: 8'],
          'Beşiktaş' => ['Sinanpaşa Mah. Mumcu Sok. No: 3', 'Abbasağa Mah. Yıldız Cad. No: 21', 'Ortaköy Mah. Dereboyu Cad. No: 17'],
          'Şişli' => ['Halaskargazi Cad. No: 120', 'Teşvikiye Mah. Hüsrev Gerede Cad. No: 65', 'Mecidiyeköy Mah. Lati Lokum Sok. No: 9'],
          'Üsküdar' => ['Mimar Sinan Mah. Atlas Sok. No: 4', 'Salacak Mah. Sahil Yolu No: 15'],
        ],
        'postal_code' => '34714',
      ],
      '06' => [
        'name' => 'Ankara',
        'districts' => [
          'Çankaya' => ['Kavaklıdere Mah. Tunalı Hilmi Cad. No: 88', 'Bahçelievler 7. Cadde No: 12', 'Ayrancı Mah. Güvenlik Cad. No: 41'],
          'Yenimahalle' => ['Batıkent Mah. 1564. Sok. No: 5', 'Demetevler Mah. Vatan Cad. No: 23'],
        ],
        'postal_code' => '06100',
      ],
      '35' => [
        'name' => 'İzmir',
        'districts' => [
          'Konak' => ['Alsancak Mah. Kıbrıs Şehitleri Cad. No: 110', 'Göztepe Mah. Mithatpaşa Cad. No: 450'],
          'Karşıyaka' => ['Bostanlı Mah. Cemal Gürsel Cad. No: 78', 'Bahriye Üçok Mah. Atatürk Bulvarı No: 12'],
        ],
        'postal_code' => '35200',
      ],
    ];

    $given_name = $first_names[array_rand($first_names)];
    $family_name = $last_names[array_rand($last_names)];
    $city_code = array_rand($cities);
    $city_info = $cities[$city_code];
    $district = array_rand($city_info['districts']);
    $address_lines = $city_info['districts'][$district];
    $address_line = $address_lines[array_rand($address_lines)];

    return [
      'country_code' => 'TR',
      'administrative_area' => $city_code,
      'locality' => $district,
      'dependent_locality' => $district . ' Mah.',
      'postal_code' => $city_info['postal_code'],
      'address_line1' => $address_line,
      'given_name' => $given_name,
      'family_name' => $family_name,
    ];
  }

}

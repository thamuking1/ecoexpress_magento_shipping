<?php

namespace Ecoexpress\Carrier\Block\Adminhtml;

class Order extends \Magento\Sales\Block\Adminhtml\Order\View
{
    public $order_id;

    public function _construct()
    {
        $total_item = 0;
        $total_weight = 0;
        $this->order_id = $this->getOrder()->getId();
        $current_order = $this->getOrder();
        $items = $this->getOrder()->getAllVisibleItems();
        foreach ($items as $item) {
            if ((int)$item->getQtyOrdered() > (int)$item->getQtyShipped()) {
                $total_item += (int)$item->getQtyOrdered() - (int)$item->getQtyShipped();
            }
            if ($item->getWeight() != 0) {
                $weight = $item->getWeight() * $item->getQtyOrdered();
            } else {
                $weight = 0.5 * $item->getQtyOrdered();
            }
            $total_weight += $weight;
        }

        $this->addBtn($current_order, $total_item);

        parent::_construct();
    }

    private function addBtn($current_order, $total_item)
    {
            if ($current_order->canShip()) {
            $this->buttonList->add('create_ecoexpress_shipment', [
                'label' => __('Create Eco Express Shipment'),
                'class' => "total_item_".$total_item
            ]);
          }
    }
}

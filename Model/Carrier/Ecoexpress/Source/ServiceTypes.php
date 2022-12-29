<?php
namespace Ecoexpress\Carrier\Model\Carrier\Ecoexpress\Source;

class ServiceTypes
{
    public function toOptionArray()
    {
        $arr[] = ['value'=>'1', 'label'=>'Normal Delivery'];
        $arr[] = ['value'=>'2', 'label'=>'Local Routed Pickup'];
        $arr[] = ['value'=>'3', 'label'=>'Return Service'];
        $arr[] = ['value'=>'4', 'label'=>'Bullet Service'];
        $arr[] = ['value'=>'5', 'label'=>'Bullet Return Service'];

        return $arr;
    }

    public function toKeyArray()
    {
        $result  = [];
        $options = $this->toOptionArray();
        foreach ($options as $option) {
            $result[$option['value']] = $option['label'];
        }
        return $result;
    }
}

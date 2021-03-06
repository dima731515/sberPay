<?php declare(strict_types=1);

namespace dima731515\SberPay;

(!$_SERVER["DOCUMENT_ROOT"])?$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www" : '';
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

require_once('/home/bitrix/.key.php');


class BxApiHelper
{
    public function __construct()
    {
        if (!\CModule::IncludeModule('iblock')) {
            throw new Exception('Не удалось подключить модуль Iblock для работы с Битикс');
        }
        if (!\CModule::IncludeModule('sale')) {
            throw new Exception('Не удалось подключить модуль Iblock для работы с Битикс');
        }
    }

    public static function getInvoiceById(int $invoiceId): ?array
    {
        if (!\CModule::IncludeModule('iblock')) {
            throw new Exception('Не удалось подключить модуль Iblock для работы с Битикс');
        }

        $arSelect = [
            'ID',
            'IBLOCK_ID',
            'NAME',
            'DETAIL_TEXT',
            'DATE_ACTIVE_TO',
            'PROPERTY_' . INVOICE_AMOUNT_FIELD_CODE,
            'PROPERTY_' . PS_ID_FIELD_CODE,
            'PROPERTY_' . PAYED_FIELD_CODE,
            'PROPERTY_' . PAY_DATE_FIELD_CODE
        ];
        $res = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID'=>INVOICE_IBLOCK_ID,'ID'=>$invoiceId],
            false,
            ['nPageSize'=>1],
            $arSelect
        );
        while ($invoice = $res->Fetch()) {
            return $invoice;
        }
        throw new \Exception('Не удалось инициализировать объект данными из Битрикс', 737);
    }

    public static function getOrderById(int $orderId): ?array
    {
        $arOrder = \CSaleOrder::GetByID($orderId);
        if (!$arOrder) {
            throw new \Exception('Заказ не найден или не удалось загрузить');
        }
        
        $arResult = [
            'ID' => $arOrder['ID'],
            'NAME' => $arOrder['ID'],
            'DETAIL_TEXT' => $arOrder['ADDITIONAL_INFO'],
            'DATE_ACTIVE_TO' => $arOrder['DATE_PAYED'],
            'PROPERTY_'. INVOICE_AMOUNT_FIELD_CODE . '_VALUE'=> $arOrder['PRICE'],
            'PROPERTY_' . PS_ID_FIELD_CODE => 590,
            'PROPERTY_PS_ID_ENUM_ID' => 590,
            'PROPERTY_'. PAYED_FIELD_CODE . '_VALUE' => $arOrder['PAYED'],
            'PROPERTY_' . PAY_DATE_FIELD_CODE . '_VALUE' => $arOrder['DATE_PAYED']
        ];

        return $arResult;
    }

    /**
     * сохраняет сылку на оплату полученную в сбер в счете Битрикс, чтобы повторно не запрашивать
     * @param string data, все, что прислал Сбер на запрос ссылки на оплату
     * @return bool
     */
    public static function setPayLinkDataInBxInvoice(int $id, string $data) : bool
    {
        $el = new \CIBlockElement;
        $prop = ['DETAIL_TEXT'=>$data];
        $res = $el->Update($id, $prop);
        return $res;
    }
    public static function setPayLinkDataInBxOrder(int $id, string $data): bool
    {
        $arFields = [
            'ADDITIONAL_INFO' =>$data,
//            'COMMENTS' =>'test2',
//            'PS_STATUS_DESCRIPTION'=>$data,
        ];
        $res = \CSaleOrder::Update($id, $arFields);
//        \CSaleOrder::CommentsOrder($id, $data);
        if ($res) {
            return true;
        }

        return false;
    }
    public static function setExternalIdForPrefektoInvoice(int $invoiceId): bool
    {
        $res = \CIBlockElement::SetPropertyValues($invoiceId, INVOICE_IBLOCK_ID, 'perfekto-' . $invoiceId . '-invoice', EXTERNAL_ID);
        return true;
    }

    public static function setInvoicePayById(int $invoiceId): bool
    {
        $res = \CIBlockElement::SetPropertyValues($invoiceId, INVOICE_IBLOCK_ID, "Y", PAYED_FIELD_CODE);
        $resPayDate = \CIBlockElement::SetPropertyValues($invoiceId, INVOICE_IBLOCK_ID, (new \DateTime())->format('d.m.Y H:i:s'), PAY_DATE_FIELD_CODE);
        return true;
    }

    public static function setOrderPayById(int $orderId): bool
    {
        if (!CSaleOrder::PayOrder($orderId, "Y", true, true, 0, array('DATE_PAYED' => new \DateTime() ))) {
            throw new \Exception('Не удаллось проставить флаг оплаты Заказу с номером: ' . $orderId);
        }
        return true;
    }
}

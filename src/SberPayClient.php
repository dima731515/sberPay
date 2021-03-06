<?php declare(strict_types=1);

namespace dima731515\SberPay;

require_once('/home/bitrix/.key.php');

use \DateTime;
use \Exception;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SwiftMailerHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Formatter\LineFormatter;

use GuzzleHttp\Client as Guzzle;

use Voronkovich\SberbankAcquiring\Client;
use Voronkovich\SberbankAcquiring\OrderStatus;
use Voronkovich\SberbankAcquiring\Currency;
use Voronkovich\SberbankAcquiring\HttpClient\HttpClientInterface;
use Voronkovich\SberbankAcquiring\HttpClient\GuzzleAdapter;

use dima731515\SberPay\BxApiHelper;

class SberPayClient
{
    /**
     * @var bool
     */
    public $isTestServer = false;

    /**
     * @var object
     */
    public $logger;

    /**
     * @var string
     * invoice/order
     */
    private $orderType = null;

    /**
     * @var string
     * 234234
     */
    private $orderNum = null;

    /**
     * @var string
     * motor/design/...
     */
    private $orderCompanyCode = null;

    /**
     * @var array
     * конфигрурация для конкретного Юрика
     */
    private $config = null;

    /**
     * @var Object
     * Объект клиента Сбер
     */
    private $client = null;

    /**
     * @var Object
     * объект SberCallback
     */
    private $sberCallback = null;

    /**
     * @var bool
     * факт оплаты
     */
    private $payed = false;


    public function __construct()
    {
        $this->logger = new Logger('sberPay.log');
        $this->logger->pushHandler(new StreamHandler(LOGER_PATH . (new DateTime())->format('d-m-Y') . '_' . LOGER_FILE_NAME, Logger::INFO, false));
    }

    /**
     * @param string $json
     * @return void
     * @see decodeExternalId(), initByOrderId()/initByInvoiceId(),  initConfigByCompanyCode()
     */
    public function sberCallback(string $json): array
    {
        try {
            $this->callback = new SberCallback($json);

            if (null === $this->callback->getExternalId()) {
                throw new Exception('Обрабатываем только запросы содержащие внешний номер заказа');
            }
            $this->decodeExternalId($this->callback->getExternalId());

            if ('invoice' === $this->orderType) {
                $init = $this->initByInvoiceId($this->orderNum);
                // 737 - очень важное число, чтобы выяснить какое именно число использовать, было преведено научаное исследование
                // Опрошены сотни программистов
                if ($init  === 737) {
                    // делаем запрос на maxlevel
                    $client = new Guzzle(['base_uri'=>'https://www.maxlevel.ru/pay/callback.php']);
                    $result = $client->request(
                        'POST',
                        '',
                        [GuzzleHttp\RequestOptions::JSON => json_decode($json),
                        'header'=> [
                            "Content-Type"=>"application/json;charset=utf-8",
                            'Accept'=> 'application/json'
                            ]
                        ]
                    );
                    return false;
                }
            } elseif ('order' === $this->orderType) {
                $init = $this->initByOrderId($this->orderNum);
            } else {
                throw new \Exception('Неизвесный тип платежа');
            };

            $this->callback->initConfig($this->config);

            $isValid = $this->callback->validateCheckSum();

            if (!$isValid) {
                throw new \Exception('Контрольная ссумма не прошла проверку');
            }

            if ($this->callback->isPayed()) {
                $payResult = $this->pay();
            } else {
                throw new \Exception('От сбербанк пришел статус, обработка которого не производится: ' . $json);
            }

            if (!$payResult) {
                throw new \Exception('Не удалось проставить факт оплаты!');
            }
        } catch (Exception $e) {
            $this->logger->info($e->getMessage(), []);
            throw new \Exception('Не удалось проставить факт оплаты!');
        }
        $this->logger->info('Обработчик запросов сбер отработал без ошибок!', []);
        return ['company'=>$this->orderCompanyCode, 'orderNum'=>$this->orderNum, 'orderType'=>$this->orderType, 'name'=>$this->bxInvoiceData['NAME']];
    }

    /**
     * Проверит, не была ли ссылка уже запрошена и сохранена в счете битрикс
     * получает возвращает ее
     * или обащается к сбербак для генерирования ссылки
     *
     * @see getBxPayLink(), getSberApiPayLink()
     * @return string, возвращает ссылку (https://sber.ru....)
     */
    public function getPayLink() : string
    {
        try {
            $res = ($this->getBxPayLink() || $this->getSberApiPayLink());
        } catch (\Exception $e) {
            //echo $e->getMessage();
            $this->logger->info($e->getMessage(), []);
            throw new \Exception('Не удалось получить ссылку на оплату, оплата невозможна! Обратитесь к администратору!');
        }
        return $this->payLink;
    }
    /**
     * Выполняет полный возврат денег, без движения средств, при этом коммисия за транзакцию не взымается
     * Можно выполнить до 24:00 текущего (дня платежа) дня
     * @param string sberOrderId, обязательный UUID заказа (не путать с номером зказа)
     * @param array data, не используется
     * @return array, ответ Сбера
     */
    public function reverseOrder(string $sberOrderId = null, array $data = []): array
    {
        if (null === $sberOrderId && null === $this->uuidSberOrderNumber) {
            $this->logger->info('Нет данных для Полного возврата!', []);
            throw new \Exception('Нет данных для Полного возврата!');
        }

        $this->uuidSberOrderNumber = ($sberOrderId) ? $sberOrderId : $this->uuidSberOrderNumber;

        $this->initBySberOrderNumber(); // для получения токена для конкретного юр.лица
        $result = $this->client->reverseOrder($this->uuidSberOrderNumber);
        $this->logger->info($result, []);
        return $result;
    }

    /**
     * Возврат денег покупателю,
     * без ограничений по дате платежа,
     * взымается коммисия с продавца,
     * можно делать частичный возврат
     * @param string sberOrderId, обязательный UUID заказа (платежа в Сбербанк)
     * @param string amoun, сумма которую нужно вернуть
     * @param array data, до параметры, не используется
     * @return array, возвращает ответ Сбербанк
     */
    public function refundOrder(string $sberOrderId = null, int $amount = null, array $data = []): array
    {
        if (null === $sberOrderId && null === $this->uuidSberOrderNumber) {
            $this->logger->info('Нет данных для Полного возврата!', []);
            throw new \Exception('Нет данных для Полного возврата!');
        }
        if (null === $amount && null === $this->summ) {
            $this->logger->info('Не указана сумма возврата, является обязательной!', []);
            throw new \Exception('Не указана сумма возврата, является обязательной!');
        }

        $this->uuidSberOrderNumber = ($sberOrderId)?$sberOrderId:$this->uuidSberOrderNumber;
        $this->summ = ($amount)?$amount:$this->summ;
        $this->initBySberOrderNumber(); // для получения токена для конкретного юр.лица
        $result = $this->client->refundOrder($this->uuidSberOrderNumber, $this->summ);
        $this->logger->info($result, []);
        return $result;
    }


    protected function pay(): bool
    {
        if ('deposited' !== $this->callback->operation || 1 != $this->callback->status) {
            $this->logger->info('Статус не соответствует оплаченному!', []);
            throw new \Exception('Статус не соответствует оплаченному!');
        }
        // сделать запрос, может фаг оплаты уже стоит
        // если стоит вернуть true
        if ($this->payed) {
            return true;
        }
        
        BxApiHelper::setInvoicePayById((int)$this->bxInvoiceData['ID']);

        return true;
    }
    /**
     * Так как сбер генерирут ссылку на оплату один раз
     * при первом получении сохраняем ссылку в Битрикс
     * данный метод проверит, нет ли ссылки в счете битрикс
     * если ссылка на оплату уже была получена в сбер, то этот метод вернет ее получив в записи инфоблока счетов
     * @return bool, !!!Возвращает только факт (ссылка установлена), саму ссылку возвращает метод getPayLink()
     */
    private function getBxPayLink() : bool
    {
        if (!isset($this->bxInvoiceData['DETAIL_TEXT']) || empty($this->bxInvoiceData['DETAIL_TEXT'])) {
            return false;
        }

        $payLink = json_decode($this->bxInvoiceData['DETAIL_TEXT'], true);
        if (!isset($payLink['formUrl']) || empty($payLink['formUrl'])) {
            return false;
        }

        BxApiHelper::setExternalIdForPrefektoInvoice((int)$this->bxInvoiceData['ID']);
        $this->payLink = $payLink['formUrl'];

        return true;
    }

    /** Регистрирует заказ в сбербанк (потуму что так работает Сбербанк)
     * сбер возвращает ссылку на оплату
     * @return bool
     */
    private function getSberApiPayLink() : bool
    {
        $this->params = [
            'failUrl' => 'https://hydroserve.ru/pay/fail.php',
            'expirationDate' => $this->dateActiveTo,
        ];
        $result = $this->client->registerOrder($this->externalId, $this->summ, $this->config['returnUrl'], $this->params);

        if (isset($result['formUrl']) && !empty($result['formUrl'])) {
            $this->payLink = $result['formUrl'];

            if ($this->orderType === 'order') {
                BxApiHelper::setPayLinkDataInBxOrder((int)$this->bxInvoiceData['ID'], json_encode($result));
            } elseif ($this->orderType === 'invoice') {
                BxApiHelper::setPayLinkDataInBxInvoice((int)$this->bxInvoiceData['ID'], json_encode($result));
                BxApiHelper::setExternalIdForPrefektoInvoice((int)$this->bxInvoiceData['ID']);
            }
            return true;
        }
        return false;
    }
    /**
     * кодирует текущий номер и тип платежа для использования в Сбер
     * @see $this->orderNum
     * @see $this->OrderType
     * @return string  234234-invoice/motor-234234-order
     */
    protected function encodeExternalId(): string
    {
        if (null === $this->orderNum || null === $this->orderType) {
            $this->logger->info($e->getMessage(), []);
            throw new Exception('Отсутствуют необходимые данные для формирования External id');
        }

        $this->externalId =  (($this->orderCompanyCode)?$this->orderCompanyCode . '-' :'') . $this->orderNum . '-' . $this->orderType;
        return $this->externalId;
    }
    /**
     * @return array
     * возвращает массив ['motors', '12123', 'invoice']
     */
    public function decodeExternalId(string $externalId): array
    {
        try {
            $result = explode('-', $externalId);

            if (2 > count($result)) {
                throw new Exception('Не верная форма externalId');
            }


            switch (count($result)) {
                case(3):
                    if (!in_array($result[0], COMPANY_CODE_BY_INVOICE_PS_ID)) {
                        throw new Exception('Не извесный код Юр. Лица, должны быть: ' . implode(', ', COMPANY_CODE_BY_INVOICE_PS_ID) . '. ');
                    }
                    if (!in_array($result[2], ORDER_TYPES)) {
                        throw new Exception('Неизвесный тип заказа, должен быть: ' . explode(self::ORDER_TYPES));
                    }
                    $this->orderCompanyCode = $result[0];
                    $this->orderNum = $result[1];
                    $this->orderType = $result[2];
                    break;
                case(2):
                    $this->orderCompanyCode = DEFAULT_COMPANY_CODE;
                    $this->orderNum = $result[0];
                    $this->orderType = $result[1];
                    break;
                default:
                    $this->logger->info('Не верный формат external id! Работа завершена, оплата не проставлена!', []);
                    throw new \Exception('Не верный формат external id! Работа завершена, оплата не проставлена!');
            }
        } catch (Exception $e) {
            $this->logger->info($e->getMessage(), []);
            //echo $e->getMessage();
        }

        return $result;
    }

    public function initByOrderId(string $orderId): bool
    {
        try {
            $this->bxInvoiceData = BxApiHelper::getOrderById((int) $orderId);
            $this->orderNum = $this->bxInvoiceData['ID'];
            $this->orderType = 'order';
            $this->summ = (int) ((float) $this->bxInvoiceData['PROPERTY_' . INVOICE_AMOUNT_FIELD_CODE . '_VALUE'] * 100);
            $this->dateActiveTo = ($this->bxInvoiceData['DATE_ACTIVE_TO'])
                ? (new \DateTime($this->bxInvoiceData['DATE_ACTIVE_TO']))->modify('+1 day')->format('Y-m-d\TH:i:s')
                : (new \DateTime())->modify('+1 day')->format('Y-m-d\TH:i:s');

            if ('Y' === $this->bxInvoiceData['PROPERTY_' . PAYED_FIELD_CODE . '_VALUE']) {
                $this->payed = true;
            }

            if ($this->bxInvoiceData['PROPERTY_' . PAY_DATE_FIELD_CODE . '_VALUE']) {
                $this->payDate = $this->bxInvoiceData['PROPERTY_' . PAY_DATE_FIELD_CODE . '_VALUE'];
            }

            $this->orderCompanyCode = (key_exists($this->bxInvoiceData['PROPERTY_PS_ID_ENUM_ID'], COMPANY_CODE_BY_INVOICE_PS_ID))
                ? COMPANY_CODE_BY_INVOICE_PS_ID[$this->bxInvoiceData['PROPERTY_PS_ID_ENUM_ID']]
                : DEFAULT_COMPANY_CODE ;

            if (isset($this->bxInvoiceData['DETAIL_TEXT']) && !empty($this->bxInvoiceData['DETAIL_TEXT'])) {
                $payLink = json_decode($this->bxInvoiceData['DETAIL_TEXT'], true);
                if (isset($payLink['orderId']) && !empty($payLink['orderId'])) {
                    $this->uuidSberOrderNumber = $payLink['orderId'];
                }
            }
            $this->encodeExternalId();
            
            $this->payLink = (isset($payLink['formUrl']) && !empty($payLink['formUrl'])) ? $payLink['formUrl'] : '';
            $this->invoiceInit = true;
            $this->initConfigByCompanyCode();
        } catch (\Exception $e) {
            $this->logger->info($e->getMessage(), []);
            throw new \Exception('Не удалось инициализиваровать Заказ!');
        }
        return true;
    }
    /**
     * Инициализирует Объект по id счета
     * запрашивает счет по id и заполняет свойства объекта
     * @param string $invoiceId
     * @see encodeExternalId()
     */
    public function initByInvoiceId(string $invoiceId): bool
    {
        try {
            $this->bxInvoiceData = BxApiHelper::getInvoiceById((int)$invoiceId);
            $this->orderNum = $this->bxInvoiceData['ID'];
            $this->orderType = 'invoice';
            $this->summ = (int) ((float) $this->bxInvoiceData['PROPERTY_' . INVOICE_AMOUNT_FIELD_CODE . '_VALUE'] * 100);
            $this->dateActiveTo = ($this->bxInvoiceData['DATE_ACTIVE_TO'])
                ? (new \DateTime($this->bxInvoiceData['DATE_ACTIVE_TO']))->modify('+1 day')->format('Y-m-d\TH:i:s')
                : (new \DateTime())->modify('+1 day')->format('Y-m-d\TH:i:s');

            if ('Y' === $this->bxInvoiceData['PROPERTY_' . PAYED_FIELD_CODE . '_VALUE']) {
                $this->payed = true;
            }

            if ($this->bxInvoiceData['PROPERTY_' . PAY_DATE_FIELD_CODE . '_VALUE']) {
                $this->payDate = $this->bxInvoiceData['PROPERTY_' . PAY_DATE_FIELD_CODE . '_VALUE'];
            }

            $this->orderCompanyCode = (key_exists($this->bxInvoiceData['PROPERTY_PS_ID_ENUM_ID'], COMPANY_CODE_BY_INVOICE_PS_ID))
                ? COMPANY_CODE_BY_INVOICE_PS_ID[$this->bxInvoiceData['PROPERTY_PS_ID_ENUM_ID']]
                : DEFAULT_COMPANY_CODE ;

            if (isset($this->bxInvoiceData['DETAIL_TEXT']) && !empty($this->bxInvoiceData['DETAIL_TEXT'])) {
                $payLink = json_decode($this->bxInvoiceData['DETAIL_TEXT'], true);
                if (isset($payLink['orderId']) && !empty($payLink['orderId'])) {
                    $this->uuidSberOrderNumber = $payLink['orderId'];
                }
            }
            
            $this->encodeExternalId();

            $this->payLink = (isset($payLink['formUrl']) && !empty($payLink['formUrl'])) ? $payLink['formUrl'] : '';
            $this->invoiceInit = true;

            $this->initConfigByCompanyCode();
        } catch (\Exception $e) {
            // todo: для GMS проверить если в Исключении содежится код отсутствия счета
            // и текущий сайт ГМС
            // отправить запрос на maxlevel (из этого метода сделать return '';)
            // вероятно оплата была ссделана там
            if ($e->getCode() === 737) {
                return 737;
            }
            $this->logger->info($e->getMessage(), []);
            throw new \Exception('Не удалось инициализиваровать счет!');
        }
        return true;
    }

    /**
     * получает и устанавливает настройки для доступа к Сбер в зависимости от Юр лица
     * @param string $code
     * @return void
     */
    protected function initConfigByCompanyCode(): bool
    {
        if (!$this->orderCompanyCode) {
            throw new Exception('Отсутствует код Юр.лица, конфигурация может быть загружена только по коду!');
        }

        if (!isset(SBER_CONFIG[$this->orderCompanyCode]) || empty(SBER_CONFIG[$this->orderCompanyCode])) {
            throw new Exception('В конфигурационном файле отсутствует Юр. лицо с кодом: ' . $this->orderCompanyCode);
        }

        $this->config = SBER_CONFIG[$this->orderCompanyCode];

        $this->config['callbackToken'] = '';
        $this->config['returnUrl'] = '';
        $this->config['sberOptions'] = [
            'apiUri'     =>'https://securepayments.sberbank.ru',
            'currency'   => Currency::RUB,
            'language'   => 'ru',
            'httpMethod' => HttpClientInterface::METHOD_GET,
            'httpClient' => new GuzzleAdapter(new Guzzle()),
        ];
        if ($this->isTestServer || $this->orderCompanyCode === null) {
            $this->orderCompanyCode = 'test';
            $this->config['sberOptions']['apiUri'] = Client::API_URI_TEST;
            $this->logger->info('Выполняется с тестовым сервером!', $this->config);
        }
        $this->config['sberOptions'] = array_merge($this->config['sberOptions'], SBER_CONFIG[$this->orderCompanyCode]['sberOptions']);
        $this->config['callbackToken'] = SBER_CONFIG[$this->orderCompanyCode]['callbackToken'];
        $this->config['returnUrl'] = SBER_CONFIG[$this->orderCompanyCode]['returnUrl'];

        $this->initClient();
        return true;
    }

    /**
    * инициализирует объект Client для работы с api сбербанк
    */
    protected function initClient(): bool
    {
        try {
            $this->client = new Client($this->config['sberOptions']);
        } catch (\Exception $e) {
            $this->logger->info($e->getMessage(), []);
            throw new \Exception('Не удалось создать объект Client');
        }
        return true;
    }
    /**
     * @return null|string
     */
    public function test() : ?string
    {
        return null;
    }
}

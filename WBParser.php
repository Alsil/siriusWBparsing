<?php

namespace App\Service\Parser;

use App\Entity\WBCategory;
use App\Entity\ParsingState;
use App\Entity\RawData;
use App\Entity\RawDataWBProduct;
use App\Repository\ParsingStateRepository;
use App\Repository\WBCategoryRepository;
use App\Repository\RawDataRepository;
use App\Repository\RawDataWBProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WBParser implements ParserInterface
{
    private array $parentsMap = [];
    private int $existedChilds = 0;
    private int $countParents = 0;
    private array $productIdList = []; // массив для id продуктов
    private array $collectionIDFromRawData = []; //массив из <= 100 id, что мы забираем при парсинге
    private WBCategoryRepository $categoryRepository;
    private ParsingStateRepository $parsingStateRepository;
    private RawDataRepository $rawDataRepository;
    private RawDataWBProductRepository $rawDataWBProductRepository;
    //номер страницы обхода продуктов стартовый равен 1, для проработки проблем с парсингом менять это значение

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->categoryRepository = $this->entityManager->getRepository(WBCategory::class);
        $this->parsingStateRepository = $this->entityManager->getRepository(ParsingState::class);
        $this->rawDataRepository = $this->entityManager->getRepository(RawData::class);
        $this->rawDataWBProductRepository = $this->entityManager->getRepository(rawDataWBProduct::class);
    }

    private function createCategory(array $categories): void
    {
        foreach ($categories as $categoryData) {
            $category = $this->categoryRepository->findOneBy(['externalID' => $categoryData['id']]);
            if (isset($categoryData['parent'])) {
                $this->parentsMap[$categoryData['id']] = $categoryData['parent'];
                $this->countParents++;
            }

            if (!$category) {
                $category = new WBCategory;
                $category->setName($categoryData['name']);
                $category->setCategoryURL($categoryData['url']);
                $category->setExternalID($categoryData['id']);
                if (isset($categoryData['shard'])) {
                    $category->setShard($categoryData['shard']);
                } else {
                    $category->setShard('0');
                }
                $this->entityManager->persist($category);
                $this->entityManager->flush();
            }
            if (array_key_exists('childs', $categoryData)) {
                $this->existedChilds++;
                $this->createCategory($categoryData['childs']);
            }
        }
    }

    private function setupParents(array $parentsMap): void
    {
        foreach ($parentsMap as $key => $value) {
            $category = $this->getWBCategoryByExternalID($key);
            $parent = $this->getWBCategoryByExternalID($value);
            $category->setParent($parent);
            $this->entityManager->persist($category);
        }
        $this->entityManager->flush();
    }

    private function getWBCategoryByExternalID(int $externalID)
    {
        $parent = $this->categoryRepository->findOneBy(
            ['externalID' => $externalID]
        );
        return $parent;
    }

    public function parse(string $url): array
    {
        //Пока коммит этот функционала, чтобы не грузить систему постоянной обработкой
        // $categories = $this->getDataFromURLRequest($url, []);
        // $this->createCategory($categories);
        // $this->setupParents($this->parentsMap);
        //$this->getWBProducts();
        $this->getAllRawDataForWBProduct();
        //$this->fromRawDataToReady();
        // Обнуляем данные в массиве ID
        $this->productIdList = [];
        return [];
    }

    private function getWBProducts(): void
    {
        //Здесь идёт первый шаг, в котором мы должны пройти по всем категориям и проверить валидацию на проход по этой категории
        $categoriesToParse = $this->categoryRepository->findBy(['shouldBeProcessed' => 'true']);

        if (!count($categoriesToParse)) {
            throw new \RuntimeException('No wb categories found');
        }

        //Обход по каждой категории
        foreach ($categoriesToParse as $categoryToParse) {
            $this->processOne($categoryToParse);
        }
    }

    private function processOne(WBCategory $categoryToParse): void
    {
        dump($categoryToParse->getName());

        //Забираем URL запроса в базу данных по категории
        $urlOfCategory = $categoryToParse->getCategoryURL(); //эти данные пока сохраним, мало ли пригодится
        $IDOfCategory = $categoryToParse->getExternalID();
        $shardOfCategory = $categoryToParse->getShard();

        //Здесь нужно обратиться к базе, чтобы проверить были ли попытки парсить данные и на каком этапе последний раз мы остановились
        //Если попытки не было, то мы должны создать стартовое значение
        //В случае этом должны сохранятся ШАРД АЙДИКАТЕГОРИИ СТРАНИЦА
        /** @var ParsingState $parseAttempt */
        $parseAttempt = $this->parsingStateRepository->findOneBy(['relation' => $categoryToParse->getId()]);

        dump($parseAttempt);
        if (!$parseAttempt) {
            $parseAttempt = new ParsingState;
            $parseAttempt->setType(ParsingState::TYPE_CATEGORY);
            $parseAttempt->setRelation($categoryToParse);
            $parseAttempt->setStateParameters(['shard' => $shardOfCategory, 'categoryID' => $IDOfCategory, 'page' => 0]);
            $this->entityManager->persist($parseAttempt);
            $this->entityManager->flush();
        }

        $parameters = $parseAttempt->getStateParameters();
        $page = $parameters['page'] + 1;

        //обход продуктов на странице
        while (true) {
            // Получение URL страницы категории, где хранятся не больше 100 продуктов
            $urlForGetInfoFromWBDatabase = $this->getURLForCategoryPages($shardOfCategory, $IDOfCategory, $page);

            // Дальше нам нужно сделать запрос по этому урлу
            // и вытащить оттуда массив продуктов и сохранить в новый массив id
            $dataCollection = $this->getDataFromURLRequest($urlForGetInfoFromWBDatabase, []);
            dump($page);

            if (!count($dataCollection)) {
                $this->entityManager->remove($parseAttempt);
                break;
            }

            // Следующим шагом достаем массив с продуктами
            $productsCollection = $dataCollection['data']['products'];

            foreach ($productsCollection as $product) {
                //сохраняем внешние Id продуктов в массив
                $this->productIdList[] = $product['id'];
            }

            //Сохраняем полученные ID в базу данных в качестве "сырых" данных
            $rawDataIDCollection = new RawData; 
            $rawDataIDCollection->setTypeOfData(RawData::TYPE_IDCOLLECTION);
            $rawDataIDCollection->setRawData($this->productIdList);
            $rawDataIDCollection->setStartAttempt(0);
            $this->entityManager->persist($rawDataIDCollection);
            $this->entityManager->flush();

            //Здесь нам нужно сохранить состояние страницы, а точнее обновить пармаметр страницы

            $parseAttempt->setStateParameters(['shard' => $shardOfCategory, 'categoryID' => $IDOfCategory, 'page' => $page]);
            $this->entityManager->persist($parseAttempt);
            $this->entityManager->flush();
            $page++;
            dump('sleep start');
            sleep(random_int(3, 7)); //Добавляем паузу между запросами не цикличную
            dump('wake up');
        }
    }

    //Берем коллекцию из ID, обходим её, забираем данные и сохраняем их как сырые данные по каждому продукту
    // Перезаписываем каждый раз в конце забора данных следующий ID на очереди, чтобы в случае 429 вернуться к формированию заново продукта и начать с этого ID

    private function getAllRawDataForWBProduct () {
        $rawData = $this->rawDataRepository->findOneBy(['typeOfData' => 'id_collection']);
        $this->collectionIDFromRawData = $rawData->getRawData();
        $startPoint = $rawData->getStartAttempt();
        // Добавим правило для обхода одного тестового продукта 
        if ($startPoint == 500) {
            $this->rawDataWBRewuests(0);
        } else {
        for ($i = $startPoint; $i < count($this->collectionIDFromRawData) ; $i++ ) {
            $this->rawDataWBRewuests($i);
            if ($i < count($this->collectionIDFromRawData)-1) {
                $j = $i + 1;
                $rawData->setStartAttempt($j);
                $this->entityManager->persist($rawData);
                $this->entityManager->flush();
            } else {
                $this->entityManager->remove($rawData);
            }
            dump('Текущая инфа' . $i);
        }}
        dump($startPoint);

    }

    private function rawDataWBRewuests (int $indexPoint) {
        $dataForProductRequest = $this->getDataForProductURL($this->collectionIDFromRawData[$indexPoint]);
            $rawDataWBProduct = new RawDataWBProduct;
            $index = $this->collectionIDFromRawData[$indexPoint];
            $basicProductInfoURL = "https://card.wb.ru/cards/v1/detail?appType=1&curr=rub&dest=-2085970&spp=28&nm=$index";
            $additionProductInfoURL = "http://basket-$dataForProductRequest[2].wbbasket.ru/vol$dataForProductRequest[0]/part$dataForProductRequest[1]/$index/info/ru/card.json";
            $sellerProductInfoURL = "http://basket-$dataForProductRequest[2].wbbasket.ru/vol$dataForProductRequest[0]/part$dataForProductRequest[1]/$index/info/sellers.json";
            $basicInfo = $this->getDataFromURLRequest($basicProductInfoURL, []);
            $sellerRatingAndSalesURL = "https://suppliers-shipment.wildberries.ru/api/v1/suppliers/" . $basicInfo['data']['products'][0]['supplierId'];
            $sellerRatingAndSales = $this->getDataFromURLRequest($sellerRatingAndSalesURL, ['x-client-name' => 'site']);
            $additionalInfo = $this->getDataFromURLRequest($additionProductInfoURL, []);
            $sellerInfo = $this->getDataFromURLRequest($sellerProductInfoURL, []);
            $rawDataWBProduct->setType('collection');
            $rawDataWBProduct->setBasicInfo($basicInfo);
            $rawDataWBProduct->setAdditionalInfo($additionalInfo);
            $rawDataWBProduct->setSellerInfo($sellerInfo);
            $rawDataWBProduct->setSellerRatingAndSells($sellerRatingAndSales);
            $createdAt = date('Y-m-d H:i:s');
            $rawDataWBProduct->setDateOfParsing($createdAt);
            $this->entityManager->persist($rawDataWBProduct);
            $this->entityManager->flush();
    }

    // private function fromRawDataToReady () {
    //     $rawDataToProcess = $this->rawDataWBProductRepository->findOneBy(['type' => 'collection']);
    //     // Собираем данные из базовой информации
    //     $basicInfo = $rawDataToProcess->getBasicInfo();
    //     $basicInfo = $basicInfo['data']['products'][0];
    //     $productID = $basicInfo['id']; // Внутренний ID продукта внутри системы ВБ
    //     $productName = $basicInfo['name']; // Название продукта
    //     $productBrand = $basicInfo['brand']; // Название бренда
    //     $productSeller = $basicInfo['suplier']; // Название продавца в системе ВБ
    //     $productCurrentPrice = $basicInfo['salePriceU']; // Цена на продукт, если есть скидка
    //     $productDefaultPrice = $basicInfo['priceU']; // Цена базовая без скидки
    //     $productReviewRating = $basicInfo['reviewRating']; // Рейтинг товара
    //     $productFeedbacksQuantity = $basicInfo['feedbacks']; // Отзывы

    // }

    private function getDataForProductURL(string $productID): array
    {
        $vol = floor($productID / 100000);
        $dataForProduct[] = (string)$vol; //значение vol
        $dataForProduct[] = (string)floor($productID / 1000); //значение part
        switch ($vol) {
            case ($vol < 144):
                $dataForProduct[] = '01';
                break;
            case ($vol < 288):
                $dataForProduct[] = '02';
                break;
            case ($vol < 432):
                $dataForProduct[] = '03';
                break;
            case ($vol < 720):
                $dataForProduct[] = '04';
                break;
            case ($vol < 1008):
                $dataForProduct[] = '05';
                break;
            case ($vol < 1062):
                $dataForProduct[] = '06';
                break;
            case ($vol < 1116):
                $dataForProduct[] = '07';
                break;
            case ($vol < 1170):
                $dataForProduct[] = '08';
                break;
            case ($vol < 1314):
                $dataForProduct[] = '09';
                break;
            case ($vol < 1602):
                $dataForProduct[] = '10';
                break;
            case ($vol < 1656):
                $dataForProduct[] = '11';
                break;
            case ($vol < 1920):
                $dataForProduct[] = '12';
                break;
            case ($vol < 2046):
                $dataForProduct[] = '13';
                break;
            case ($vol <= 2189):
                $dataForProduct[] = '14';
                break;
            default;
                $dataForProduct[] = '15';
                break;
        }
        return $dataForProduct; //[vol, part, basket]
    } //все условия, требуемые, чтобы нам получить нужный и правильный URL

    private function existsInArray(array $categories, string $name): bool
    {
        foreach ($categories as $category) {
            if ($category->getName() === $name) {
                return true;
            }
        }

        return false;
    }
    //функция для получения данных по запросу с конкретного URL
    private function getDataFromURLRequest(string $url, array $headers)
    {

        $response = $this->httpClient->request(
            'GET',
            $url,
            [
                'headers' => $headers
            ]
        );
        $content = $response->toArray();
        usleep(random_int(0, 500000));
        return $content;
    }
    private function getURLForCategoryPages($shard, $id, $page): string
    {
        // TODO: переписать используя http_build_url()
        return "https://catalog.wb.ru/catalog/$shard/catalog?TestGroup=sim_goods_rec_infra&TestID=367&appType=1&cat=$id&curr=rub&dest=-1257786&page=$page&sort=popular&spp=28";
    }
}

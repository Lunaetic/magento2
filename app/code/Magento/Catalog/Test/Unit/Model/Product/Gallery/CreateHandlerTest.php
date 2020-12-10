<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\Gallery;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Repository;
use Magento\Catalog\Model\Product\Gallery\CreateHandler;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Store\Model\StoreManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Catalog Product Gallery CreateHandler
 */
class CreateHandlerTest extends TestCase
{
    /**
    * @var Repository|MockObject
    */
    protected $attributeRepositoryMock;

    /**
     * @var Database|MockObject
     */
    protected $filestorageDbMock;

    /**
     * @var Filesystem|MockObject
     */
    protected $filesystemMock;

    /**
     * @var Json|MockObject
     */
    protected $jsonMock;

    /**
     * @var Config|MockObject
     */
    protected $mediaConfigMock;

    /**
     * @var Write|MockObject
     */
    protected $mediaDirectoryMock;

    /**
     * @var EntityMetadata|MockObject
     */
    protected $metadataMock;

    /**
     * @var MetadataPool|MockObject
     */
    protected $metadataPoolMock;

    /**
     * @var CreateHandler
     */
    protected $model;

    /**
     * @var Gallery|MockObject
     */
    protected $resourceModelMock;

    /**
     * @var StoreManager|MockObject
     */
    protected $storeManagerMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->attributeRepositoryMock = $this->createPartialMock(
            Repository::class,
            ['get']
        );

        $this->filestorageDbMock = $this->createPartialMock(
            Database::class,
            ['checkDbUsage', 'getUniqueFilename', 'renameFile']
        );

        $this->filesystemMock = $this->createPartialMock(
            Filesystem::class,
            ['getDirectoryWrite']
        );

        $this->jsonMock = $this->createPartialMock(
            Json::class,
            ['unserialize']
        );

        $this->metadataMock = $this->createPartialMock(
            EntityMetadata::class,
            ['getLinkField']
        );

        $this->mediaConfigMock = $this->createPartialMock(
            Config::class,
            [
                'getBaseMediaUrlAddition',
                'getMediaAttributeCodes',
                'getMediaPath',
                'getTmpMediaPath'
            ]
        );

        $this->mediaDirectoryMock = $this->createPartialMock(
            Write::class,
            ['getAbsolutePath', 'getDriver', 'renameFile']
        );

        $this->metadataPoolMock = $this->createPartialMock(
            MetadataPool::class,
            ['getMetadata']
        );

        $this->storeManagerMock = $this->createPartialMock(
            StoreManager::class,
            ['getStores', 'hasSingleStore']
        );

        $this->resourceModelMock = $this->createPartialMock(
            Gallery::class,
            ['bindValueToEntity', 'getProductImages', 'insertGallery', 'insertGalleryValueInStore']
        );

        $this->model = $this->createPartialMock(
            CreateHandler::class,
            ['getNewFileName']
        );

        $this->setPropertyValues(
            $this->model,
            [
                'attributeRepository' => $this->attributeRepositoryMock,
                'fileStorageDb' => $this->filestorageDbMock,
                'json' => $this->jsonMock,
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'metadata' => $this->metadataMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock
            ]
        );
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueNotArray()
    {
        $attribute = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode']
        );

        $attribute->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn('media_gallery');

        $this->attributeRepositoryMock->expects($this->once())
            ->method('get')
            ->with('media_gallery')
            ->willReturn($attribute);

        $productMock = $this->createPartialMock(
            Product::class,
            ['getData']
        );

        $productMock->expects($this->once())
            ->method('getData')
            ->with('media_gallery')
            ->willReturn(null);

        $returnValue = $this->model->execute($productMock, []);

        $this->assertSame($returnValue, $productMock);
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueNotContainImages()
    {
        $attribute = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode']
        );

        $attribute->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn('media_gallery');

        $this->attributeRepositoryMock->expects($this->once())
            ->method('get')
            ->with('media_gallery')
            ->willReturn($attribute);

        $productMock = $this->createPartialMock(
            Product::class,
            ['getData']
        );

        $productMock->expects($this->once())
            ->method('getData')
            ->with('media_gallery')
            ->willReturn(['testKey' => 'testValue']);

        $returnValue = $this->model->execute($productMock, []);

        $this->assertSame($returnValue, $productMock);
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueContainsJsonNotDuplicateNotDbStorageSingleStore()
    {
        $productMock = $this->getMockBuilder(Product::class)
            ->onlyMethods(['addAttributeUpdate', 'getData', 'getStoreId', 'isObjectNew', 'setData'])
            ->addMethods(['getIsDuplicate'])
            ->disableOriginalConstructor()
            ->getMock();

        $attributeMock = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode', 'getAttributeId']
        );

        $attributeMock->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn('media_gallery');

        $this->attributeRepositoryMock->expects($this->once())
            ->method('get')
            ->with('media_gallery')
            ->willReturn($attributeMock);

        $productMock->expects($this->exactly(13))
            ->method('getData')
            ->withConsecutive(
                ['media_gallery'],
                ['image'],
                ['image'],
                ['image_label'],
                ['small_image'],
                ['small_image'],
                ['small_image_label'],
                ['thumbnail'],
                ['thumbnail'],
                ['thumbnail_label'],
                ['swatch_image'],
                ['link_field'],
                ['link_field']
            )
            ->willReturnOnConsecutiveCalls(
                ['images' => '{"9rm40a2fvqd":{"position":"1","media_type":"image","video_provider":"","file":"\/k\/i\/test.jpeg.tmp","value_id":"","label":"","disabled":"0","removed":"","video_url":"","video_title":"","video_description":"","video_metadata":"","role":""}}'],
                '/k/i/test.jpg',
                '/k/i/test.jpg',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                ''
            );

        $productMock->expects($this->exactly(2))
            ->method('getIsDuplicate')
            ->willReturn(false);

        $this->jsonMock->expects($this->once())
            ->method('unserialize')
            ->with('{"9rm40a2fvqd":{"position":"1","media_type":"image","video_provider":"","file":"\/k\/i\/test.jpeg.tmp","value_id":"","label":"","disabled":"0","removed":"","video_url":"","video_title":"","video_description":"","video_metadata":"","role":""}}')
            ->willReturn([
                "9rm40a2fvqd" => [
                    "position" => "1",
                    "media_type" => "image",
                    "video_provider" => "",
                    "file" => "/k/i/test.jpeg.tmp",
                    "value_id" => "",
                    "label" => "",
                    "disabled" => "0",
                    "removed" => "",
                    "video_url" => "",
                    "video_title" => "",
                    "video_description" => "",
                    "video_metadata" => "",
                    "role" => ""
                ]
            ]);

        $driverMock = $this->createPartialMock(
            File::class,
            ['getRealPathSafety']
        );

        $this->mediaDirectoryMock->expects($this->once())
            ->method('getDriver')
            ->willReturn($driverMock);

        $driverMock->expects($this->once())
            ->method('getRealPathSafety')
            ->with("/k/i/test.jpeg.tmp")
            ->willReturn('/k/i/test.jpeg.tmp');

        $this->filestorageDbMock->expects($this->exactly(2))
            ->method('checkDbUsage')
            ->willReturn(false);

        $this->mediaConfigMock->expects($this->exactly(2))
            ->method('getMediaPath')
            ->willReturn('/k/i/test.jpeg');

        $this->mediaDirectoryMock->expects($this->once())
            ->method('getAbsolutePath')
            ->with('/k/i/test.jpeg')
            ->willReturn('/k/i/test.jpeg');

        $this->model->expects($this->once())
            ->method('getNewFileName')
            ->with('/k/i/test.jpeg')
            ->willReturn('test.jpeg');

        $this->mediaConfigMock->expects($this->once())
            ->method('getTmpMediaPath')
            ->with('/k/i/test.jpeg')
            ->willReturn('/k/i/test.jpeg');

        $this->mediaDirectoryMock->expects($this->once())
            ->method('renameFile')
            ->with('/k/i/test.jpeg', '/k/i/test.jpeg');

        $this->mediaConfigMock->expects($this->once())
            ->method('getMediaAttributeCodes')
            ->willReturn(["image", "small_image", "thumbnail", "swatch_image"]);

        $productMock->expects($this->exactly(4))
            ->method("isObjectNew")
            ->willReturn(true);

        $attributeMock->expects($this->once())
            ->method('getAttributeId')
            ->willReturn(42);

        $this->resourceModelMock->expects($this->once())
            ->method('insertGallery')
            ->with([
                'value' => '/k/i/test.jpeg',
                'attribute_id' => 42,
                'media_type' => 'image'
            ])
            ->willReturn(23);

        $this->resourceModelMock->expects($this->once())
            ->method('bindValueToEntity')
            ->with(23, '');

        $this->metadataMock->expects($this->exactly(3))
            ->method('getLinkField')
            ->willReturn('link_field');

        $productMock->expects($this->once())
            ->method('getStoreId')
            ->willReturn(0);

        $this->resourceModelMock->expects($this->once())
            ->method('insertGalleryValueInStore')
            ->with([
                'value' => '/k/i/test.jpeg',
                'attribute_id' => 42,
                'media_type' => 'image',
                'value_id' => 23,
                'label' => '',
                'position' => 1,
                'disabled' => 0,
                'store_id' => 0,
                'link_field' => 0
            ]);

        $this->model->execute($productMock, []);
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueIsArrayNotDuplicateNotDbStorageSingleStore()
    {
        $productMock = $this->getMockBuilder(Product::class)
            ->onlyMethods(['addAttributeUpdate', 'getData', 'getStoreId', 'isObjectNew', 'setData'])
            ->addMethods(['getIsDuplicate'])
            ->disableOriginalConstructor()
            ->getMock();

        $attributeMock = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode', 'getAttributeId']
        );

        $attributeMock->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn('media_gallery');

        $this->attributeRepositoryMock->expects($this->once())
            ->method('get')
            ->with('media_gallery')
            ->willReturn($attributeMock);

        $productMock->expects($this->exactly(13))
            ->method('getData')
            ->withConsecutive(
                ['media_gallery'],
                ['image'],
                ['image'],
                ['image_label'],
                ['small_image'],
                ['small_image'],
                ['small_image_label'],
                ['thumbnail'],
                ['thumbnail'],
                ['thumbnail_label'],
                ['swatch_image'],
                ['link_field'],
                ['link_field']
            )
            ->willReturnOnConsecutiveCalls(
                ['images' => [ '9rm40a2fvqd' => ['position' => 1,
                            'media_type' => 'image',
                            'video_provider' => '',
                            'file' => '/k/i/test.jpeg.tmp',
                            'value_id' => '',
                            'label' => '',
                            'disabled' => 0,
                            'removed' => '',
                            'video_url' => '',
                            'video_title' => '',
                            'video_description' => '',
                            'video_metadata' => '',
                            'role' => '']
                        ]
                    ],
                '/k/i/test.jpg',
                '/k/i/test.jpg',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                ''
            );

        $productMock->expects($this->exactly(2))
            ->method('getIsDuplicate')
            ->willReturn(false);

        $driverMock = $this->createPartialMock(
            File::class,
            ['getRealPathSafety']
        );

        $this->mediaDirectoryMock->expects($this->once())
            ->method('getDriver')
            ->willReturn($driverMock);

        $driverMock->expects($this->once())
            ->method('getRealPathSafety')
            ->with("/k/i/test.jpeg.tmp")
            ->willReturn('/k/i/test.jpeg.tmp');

        $this->filestorageDbMock->expects($this->exactly(2))
            ->method('checkDbUsage')
            ->willReturn(false);

        $this->mediaConfigMock->expects($this->exactly(2))
            ->method('getMediaPath')
            ->willReturn('/k/i/test.jpeg');

        $this->mediaDirectoryMock->expects($this->once())
            ->method('getAbsolutePath')
            ->with('/k/i/test.jpeg')
            ->willReturn('/k/i/test.jpeg');

        $this->model->expects($this->once())
            ->method('getNewFileName')
            ->with('/k/i/test.jpeg')
            ->willReturn('test.jpeg');

        $this->mediaConfigMock->expects($this->once())
            ->method('getTmpMediaPath')
            ->with('/k/i/test.jpeg')
            ->willReturn('/k/i/test.jpeg');

        $this->mediaDirectoryMock->expects($this->once())
            ->method('renameFile')
            ->with('/k/i/test.jpeg', '/k/i/test.jpeg');

        $this->mediaConfigMock->expects($this->once())
            ->method('getMediaAttributeCodes')
            ->willReturn(["image", "small_image", "thumbnail", "swatch_image"]);

        $productMock->expects($this->exactly(4))
            ->method("isObjectNew")
            ->willReturn(true);

        $attributeMock->expects($this->once())
            ->method('getAttributeId')
            ->willReturn(42);

        $this->resourceModelMock->expects($this->once())
            ->method('insertGallery')
            ->with([
                'value' => '/k/i/test.jpeg',
                'attribute_id' => 42,
                'media_type' => 'image'
            ])
            ->willReturn(23);

        $this->resourceModelMock->expects($this->once())
            ->method('bindValueToEntity')
            ->with(23, '');

        $this->metadataMock->expects($this->exactly(3))
            ->method('getLinkField')
            ->willReturn('link_field');

        $productMock->expects($this->once())
            ->method('getStoreId')
            ->willReturn(0);

        $this->resourceModelMock->expects($this->once())
            ->method('insertGalleryValueInStore')
            ->with([
                'value' => '/k/i/test.jpeg',
                'attribute_id' => 42,
                'media_type' => 'image',
                'value_id' => 23,
                'label' => '',
                'position' => 1,
                'disabled' => 0,
                'store_id' => 0,
                'link_field' => 0
            ]);

        $this->model->execute($productMock, []);
    }

    /**
     * Set property values using reflection
     *
     * @param $object
     * @param $propertyValueArray
     * @return mixed
     * @throws \ReflectionException
     */
    protected function setPropertyValues(&$object, $propertyValueArray)
    {
        $reflection = new \ReflectionClass(get_class($object));

        foreach ($propertyValueArray as $property => $value) {
            $reflectionProperty = $reflection->getProperty($property);
            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($object, $value);
        }

        return $object;
    }
}

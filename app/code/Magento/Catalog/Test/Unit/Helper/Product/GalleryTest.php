<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Helper\Product;

use Magento\Catalog\Helper\Product\Gallery;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery as GalleryResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

/**
 */
class GalleryTest extends TestCase
{
    /**
     * @var Gallery|MockObject
     */
    protected $subject;

    /**
     * @var Config|MockObject
     */
    protected $mediaConfigMock;

    /**
     * @var Write|MockObject
     */
    protected $mediaDirectoryMock;

    /**
     * @var GalleryResource|MockObject
     */
    protected $resourceModelMock;

    /**
     * @var StoreManager|MockObject
     */
    protected $storeManageMock;

    /**
     * @var Database|MockObject
     */
    protected $fileStorageDbMock;

    /**
     */
    public function setUp(): void
    {
        $this->mediaConfigMock = $this->createPartialMock(
            Config::class,
            ['getBaseMediaPath', 'getMediaPath', 'getMediaShortUrl']
        );

        $this->mediaDirectoryMock = $this->createPartialMock(
            Write::class,
            ['isFile', 'getAbsolutePath', 'delete', 'copyFile']
        );

        $this->resourceModelMock = $this->createPartialMock(
            GalleryResource::class,
            ['countImageUses', 'getProductImages']
        );

        $this->storeManageMock = $this->createPartialMock(
            StoreManager::class,
            ['getStores']
        );

        $this->fileStorageDbMock = $this->createPartialMock(
            Database::class,
            ['checkDbUsage', 'copyFile']
        );

        $objectManager = new ObjectManager($this);

        $this->subject = $objectManager->getObject(
            Gallery::class,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManageMock,
                'fileStorageDb' => $this->fileStorageDbMock
            ]
        );
    }

    /**
     * @return array
     */
    public function getCanDeleteImageDataProvider(): array
    {
        return [
            [true, 0, true],
            [true, 1, true],
            [true, 10, false],
            [false, 0, false],
            [false, 1, false],
            [false, 10, false]
        ];
    }

    /**
     * @param $isFileReturn
     * @param $countImageUsesReturn
     * @param $expected
     * @throws LocalizedException
     *
     * @dataProvider getCanDeleteImageDataProvider
     */
    public function testCanDeleteImage($isFileReturn, $countImageUsesReturn, $expected): void
    {
        $this->mediaConfigMock->expects($this->once())
            ->method('getBaseMediaPath')
            ->willReturn('base/media/path/');

        $this->mediaDirectoryMock->expects($this->once())
            ->method('isFile')
            ->with('base/media/path/test.jpg')
            ->willReturn($isFileReturn);

        if ($isFileReturn) {
            $this->resourceModelMock->expects($this->once())
                ->method('countImageUses')
                ->with('test.jpg')
                ->willReturn($countImageUsesReturn);
        }

        $actual = $this->subject->canDeleteImage('test.jpg');

        $this->assertEquals($expected, $actual);
    }

    /**
     * @return array
     */
    public function getCanRemoveImageDataProvider(): array
    {
        return [
            [
                false,
                [1, 0],
                [[
                    'store_id' => 1,
                    'filepath' => 'test.jpg'
                ]],
                true
            ],
            [
                true,
                [1, 2, 0],
                [[
                    'store_id' => 2,
                    'filepath' => 'test.jpg'
                ]],
                false
            ],
            [
                false,
                [1, 0],
                [[
                    'store_id' => 1,
                    'filepath' => 'nope.jpg'
                ]],
                true
            ]
        ];
    }

    /**
     * @param $includeStoreId2
     * @param $getProductImagesParams
     * @param $getProductImagesReturn
     * @param $expected
     *
     * @dataProvider getCanRemoveImageDataProvider
     */
    public function testCanRemoveImage($includeStoreId2, $getProductImagesParams, $getProductImagesReturn, $expected)
    {
        $productMock = $this->createPartialMock(
            Product::class,
            ['getStoreId', 'getWebsiteIds']
        );

        $storeMock = $this->createPartialMock(
            Store::class,
            ['getId', 'getWebsiteId']
        );

        $this->storeManageMock->expects($this->exactly(2))
            ->method('getStores')
            ->willReturnCallback(function () use ($includeStoreId2, $storeMock) {
                if (!$includeStoreId2) {
                    return [
                        1 => $storeMock
                    ];
                } else {
                    return [
                        1 => $storeMock,
                        2 => $storeMock
                    ];
                }
            });

        $this->resourceModelMock->expects($this->once())
            ->method('getProductImages')
            ->with($productMock, $getProductImagesParams)
            ->willReturn($getProductImagesReturn);

        $productMock->expects($this->once())
            ->method('getStoreId')
            ->willReturn(1);

        if (!$includeStoreId2) {
            $productMock->expects($this->once())
                ->method('getWebsiteIds')
                ->willReturn([
                    '1'
                ]);

            $storeMock->expects($this->once())
                ->method('getWebsiteId')
                ->willReturn(1);

            $storeMock->expects($this->once())
                ->method('getId')
                ->willReturn('1');
        } else {
            $productMock->expects($this->once())
                ->method('getWebsiteIds')
                ->willReturn([
                    '1', '2'
                ]);

            $storeMock->expects($this->exactly(2))
                ->method('getWebsiteId')
                ->willReturnOnConsecutiveCalls(1, 2);

            $storeMock->expects($this->exactly(2))
                ->method('getId')
                ->willReturn('1', '2');
        }

        $actual = $this->subject->canRemoveImage($productMock, 'test.jpg');

        $this->assertEquals($expected, $actual);
    }

    /**
     * @throws LocalizedException
     * @throws ReflectionException
     */
    public function testCopyImageWithException(): void
    {
        $this->subject = $this->createPartialMock(
            Gallery::class,
            ['getUniqueFilename']
        );

        $this->setPropertyValues(
            $this->subject,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManageMock,
                'fileStorageDb' => $this->fileStorageDbMock
            ]
        );

        $this->subject->expects($this->once())
            ->method('getUniqueFilename')
            ->willReturn('test.jpg');

        $this->mediaConfigMock->expects($this->exactly(2))
            ->method('getMediaPath')
            ->with('test.jpg')
            ->willReturn('media/test.jpg');

        $this->mediaDirectoryMock->expects($this->once())
            ->method('isFile')
            ->with('media/test.jpg')
            ->willReturn(false);

        $this->expectException(LocalizedException::class);

        $this->subject->copyImage('test.jpg');
    }

    /**
     * @return array
     */
    public function getCopyImageDataProvider(): array
    {
        return [
            [
                true,
                2
            ],
            [
                false,
                3
            ]
        ];
    }

    /**
     * @param $checkDbUsageReturn
     * @param $getMediaPathTimes
     * @throws LocalizedException
     * @throws ReflectionException
     *
     * @dataProvider getCopyImageDataProvider
     */
    public function testCopyImage($checkDbUsageReturn, $getMediaPathTimes): void
    {
        $this->subject = $this->createPartialMock(
            Gallery::class,
            ['getUniqueFilename']
        );

        $this->setPropertyValues(
            $this->subject,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManageMock,
                'fileStorageDb' => $this->fileStorageDbMock
            ]
        );

        $this->subject->expects($this->once())
            ->method('getUniqueFilename')
            ->willReturn('test.jpg');

        $this->mediaConfigMock->expects($this->exactly($getMediaPathTimes))
            ->method('getMediaPath')
            ->with('test.jpg')
            ->willReturn('media/test.jpg');

        $this->mediaDirectoryMock->expects($this->once())
            ->method('isFile')
            ->with('media/test.jpg')
            ->willReturn(true);

        $this->fileStorageDbMock->expects($this->once())
            ->method('checkDbUsage')
            ->willReturn($checkDbUsageReturn);

        if ($checkDbUsageReturn) {
            $this->fileStorageDbMock->expects($this->once())
                ->method('copyFile')
                ->with('absolute/media/test.jpg', 'media/test.jpg');

            $this->mediaDirectoryMock->expects($this->once())
                ->method('getAbsolutePath')
                ->with('media/test.jpg')
                ->willReturn('absolute/media/test.jpg');

            $this->mediaConfigMock->expects($this->exactly(2))
                ->method('getMediaShortUrl')
                ->with('test.jpg')
                ->willReturn('media/test.jpg');

            $this->mediaDirectoryMock->expects($this->once())
                ->method('delete')
                ->with('media/test.jpg');

        } else {
            $this->mediaDirectoryMock->expects($this->once())
                ->method('copyFile')
                ->with('media/test.jpg', 'media/test.jpg');
        }

        $actual = $this->subject->copyImage('test.jpg');

        $this->assertEquals($actual, 'test.jpg');
    }

    /**
     * @param $object
     * @param $propertyValuesArray
     * @return object
     * @throws ReflectionException
     */
    private function setPropertyValues($object, $propertyValuesArray): object
    {
        $reflection = new ReflectionClass(get_class($object));

        foreach ($propertyValuesArray as $property => $value) {
            $reflectionProperty = $reflection->getProperty($property);
            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($object, $value);
        }

        return $object;
    }
}

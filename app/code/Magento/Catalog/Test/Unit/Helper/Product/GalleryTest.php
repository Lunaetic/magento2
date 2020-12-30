<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Helper\Product;

use Magento\Catalog\Helper\Product\Gallery;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Repository;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Gallery as GalleryResource;
use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Filesystem\DriverInterface;
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
    protected $storeManagerMock;

    /**
     * @var Database|MockObject
     */
    protected $fileStorageDbMock;

    /**
     * @var Repository|MockObject
     */
    protected $attributeRepositoryMock;

    /**
     * @var EntityMetadata|MockObject
     */
    protected $metadataMock;

    /**
     */
    public function setUp(): void
    {
        $this->mediaConfigMock = $this->createPartialMock(
            Config::class,
            ['getBaseMediaPath', 'getMediaPath', 'getMediaShortUrl', 'getMediaAttributeCodes', 'getBaseMediaUrlAddition', 'getTmpMediaShortUrl', 'getTmpMediaPath']
        );

        $this->mediaDirectoryMock = $this->createPartialMock(
            Write::class,
            ['isFile', 'getAbsolutePath', 'delete', 'copyFile', 'getDriver', 'renameFile']
        );

        $this->resourceModelMock = $this->createPartialMock(
            GalleryResource::class,
            ['countImageUses', 'getProductImages', 'duplicate', 'deleteGallery', 'loadDataFromTableByValueId', 'updateGalleryValueInStore', 'insertGalleryValueInStore']
        );

        $this->storeManagerMock = $this->createPartialMock(
            StoreManager::class,
            ['getStores']
        );

        $this->fileStorageDbMock = $this->createPartialMock(
            Database::class,
            ['checkDbUsage', 'copyFile', 'getUniqueFileName', 'renameFile']
        );

        $this->attributeRepositoryMock = $this->createPartialMock(
            Repository::class,
            ['get']
        );

        $this->metadataMock = $this->createPartialMock(
            EntityMetadata::class,
            ['getLinkField']
        );

        $objectManager = new ObjectManager($this);

        $this->subject = $objectManager->getObject(
            Gallery::class,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock,
                'fileStorageDb' => $this->fileStorageDbMock,
                'attributeRepository' => $this->attributeRepositoryMock,
                'metadata' => $this->metadataMock
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

        $this->storeManagerMock->expects($this->exactly(2))
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
            ['getUniqueFileName']
        );

        $this->setPropertyValues(
            $this->subject,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock,
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
            [true, 2],
            [false, 3]
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
            ['getUniqueFileName']
        );

        $this->setPropertyValues(
            $this->subject,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock,
                'fileStorageDb' => $this->fileStorageDbMock
            ]
        );

        $this->subject->expects($this->once())
            ->method('getUniqueFileName')
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

        $this->assertEquals('test.jpg', $actual);
    }

    /**
     * @return array
     */
    public function getTestDuplicateDataProvider(): array
    {
        return [
            [null],
            [
                ['images' => []]
            ]
        ];
    }

    /**
     * @param $imagesReturn
     * @throws LocalizedException
     * @throws NoSuchEntityException
     *
     * @dataProvider getTestDuplicateDataProvider
     */
    public function testDuplicate($imagesReturn): void
    {
        $attributeMock = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode', 'getAttributeId']
        );

        $productMock = $this->createPartialMock(
            Product::class,
            ['getData', 'getOriginalLinkId']
        );

        $this->attributeRepositoryMock->expects($this->once())
            ->method('get')
            ->with('media_gallery')
            ->willReturn($attributeMock);

        $attributeMock->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn('media_gallery');

        if (!isset($imagesReturn['images'])) {
            $productMock->expects($this->once())
                ->method('getData')
                ->with('media_gallery')
                ->willReturn([]);

            $attributeMock->expects($this->never())
                ->method('getAttributeId');
        } else {
            $productMock->expects($this->exactly(2))
                ->method('getData')
                ->withConsecutive(['media_gallery'], ['link_field'])
                ->willReturnOnConsecutiveCalls($imagesReturn, 'link_field');

            $attributeMock->expects($this->once())
                ->method('getAttributeId')
                ->willReturn(42);

            $productMock->expects($this->once())
                ->method('getOriginalLinkId')
                ->willReturn(23);

            $this->metadataMock->expects($this->once())
                ->method('getLinkField')
                ->willReturn('link_field');

            $this->resourceModelMock->expects($this->once())
                ->method('duplicate')
                ->with(42, [], 23, 'link_field');
        }

        $this->subject->duplicate($productMock);
    }

    /**
     * @throws NoSuchEntityException
     */
    public function testGetAttribute(): void
    {
        $this->attributeRepositoryMock->expects($this->once())
            ->method('get')
            ->with('media_gallery');

        $this->subject->getAttribute();
    }

    /**
     * @return array
     */
    public function getGetFilenameFromTmpProvider(): array
    {
        return [
            ['test.jpg.tmp'],
            ['test.jpg']
        ];
    }

    /**
     * @param $fileName
     *
     * @dataProvider getGetFilenameFromTmpProvider
     */
    public function testGetFilenameFromTmp($fileName): void
    {
        $return = $this->subject->getFilenameFromTmp($fileName);

        $this->assertEquals('test.jpg', $return);
    }

    /**
     */
    public function testGetImagesForAllStores(): void
    {
        $productMock = $this->createMock(Product::class);

        $storeMock = $this->createMock(Store::class);

        $this->storeManagerMock->expects($this->once())
            ->method('getStores')
            ->willReturn([1 => $storeMock]);

        $this->resourceModelMock->expects($this->once())
            ->method('getProductImages')
            ->with($productMock, [1, 0])
            ->willReturn(['images']);

        $this->subject->getImagesForAllStores($productMock);
    }

    /**
     */
    public function testGetMediaAttributeCodes(): void
    {
        $this->mediaConfigMock->expects($this->once())
            ->method('getMediaAttributeCodes')
            ->willReturn(['media_gallery']);

        $this->subject->getMediaAttributeCodes();
    }

    /**
     * @return array
     */
    public function getMediaAttributeStoreValueProvider(): array
    {
        return [
            [
                1,
                'test_attribute',
                'test.jpg'
            ],
            [
                1,
                'bad_attribute',
                null
            ],
            [
                2,
                'test_attribute',
                null
            ],
        ];
    }

    /**
     * @param $storeId
     * @param $attributeCode
     * @param $return
     *
     * @dataProvider getMediaAttributeStoreValueProvider
     */
    public function testGetMediaAttributeStoreValue($storeId, $attributeCode, $return): void
    {
        $productMock = $this->createMock(Product::class);

        $storeMock = $this->createMock(Store::class);

        $this->storeManagerMock->expects($this->once())
            ->method('getStores')
            ->willReturn([1 => $storeMock]);

        $this->resourceModelMock->expects($this->once())
            ->method('getProductImages')
            ->with($productMock, [1, 0])
            ->willReturn(['images' => [
                'attribute_code' => 'test_attribute',
                'store_id' => 1,
                'filepath' => 'test.jpg'
            ]]);

        $actual = $this->subject->getMediaAttributeStoreValue($productMock, $attributeCode, $storeId);

        $this->assertEquals($return, $actual);
    }

    /**
     * @return array
     */
    public function getSafeFilenameProvider(): array
    {
        return [
            ['test.jpg'],
            ['/test.jpg']
        ];
    }

    /**
     * @param $in
     *
     * @dataProvider getSafeFilenameProvider
     */
    public function testGetSafeFilename($in): void
    {
        $driverMock = $this->createMock(DriverInterface::class);

        $this->mediaDirectoryMock->expects($this->once())
            ->method('getDriver')
            ->willReturn($driverMock);

        $driverMock->expects($this->once())
            ->method('getRealPathSafety')
            ->with('/test.jpg')
            ->willReturn('/test.jpg');

        $actual = $this->subject->getSafeFilename($in);

        $this->assertEquals('/test.jpg', $actual);
    }

    /**
     * @return array
     */
    public function getUniqueFileNameProvider(): array
    {
        return [
            ['/media/tmp.jpg', true, true],
            ['/media/tmp.jpg', false, true],
            ['/media/tmp.jpg', false, false]
        ];
    }

    /**
     * @param $dbUsage
     * @param $forTmp
     * @throws ReflectionException
     *
     * @dataProvider getUniqueFileNameProvider
     */
    public function testGetUniqueFileName($dbUsage, $forTmp): void
    {
        $this->subject = $this->createPartialMock(
            Gallery::class,
            ['getNewFileName']
        );

        $this->setPropertyValues(
            $this->subject,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock,
                'fileStorageDb' => $this->fileStorageDbMock
            ]
        );

        $this->fileStorageDbMock->expects($this->once())
            ->method('checkDbUsage')
            ->willReturn($dbUsage);

        if ($dbUsage) {
            $this->mediaConfigMock->expects($this->once())
                ->method('getBaseMediaUrlAddition')
                ->willReturn('/base/');

            $this->fileStorageDbMock->expects($this->once())
                ->method('getUniqueFileName')
                ->with('/base/', 'tmp.jpg')
                ->willReturn('/base/tmp.jpg');
        } else {
            if ($forTmp) {
                $this->mediaConfigMock->expects($this->once())
                    ->method('getTmpMediaPath')
                    ->with('tmp.jpg')
                    ->willReturn('tmp.jpg');
            } else {
                $this->mediaConfigMock->expects($this->once())
                    ->method('getMediaPath')
                    ->with('tmp.jpg')
                    ->willReturn('tmp.jpg');
            }

            $this->mediaDirectoryMock->expects($this->once())
                ->method('getAbsolutePath')
                ->with('tmp.jpg')
                ->willReturn('tmp.jpg');

            $this->subject->expects($this->once())
                ->method('getNewFileName')
                ->with('tmp.jpg');
        }

        $this->subject->getUniqueFileName('tmp.jpg', $forTmp);
    }

    /**
     * @return array
     */
    public function getMoveImageFromTmpProvider(): array
    {
        return [
            [true],
            [false]
        ];
    }

    /**
     * @param $dbUsage
     * @throws FileSystemException
     * @throws ReflectionException
     *
     * @dataProvider getMoveImageFromTmpProvider
     */
    public function testMoveImageFromTmp($dbUsage): void
    {
        $this->subject = $this->createPartialMock(
            Gallery::class,
            ['getUniqueFileName']
        );

        $this->setPropertyValues(
            $this->subject,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock,
                'fileStorageDb' => $this->fileStorageDbMock
            ]
        );

        $driverMock = $this->createMock(DriverInterface::class);

        $this->mediaDirectoryMock->expects($this->once())
            ->method('getDriver')
            ->willReturn($driverMock);

        $driverMock->expects($this->once())
            ->method('getRealPathSafety')
            ->with('/test.jpg.tmp')
            ->willReturn('test.jpg.tmp');

        $this->subject->expects($this->once())
            ->method('getUniqueFileName')
            ->with('test.jpg')
            ->willReturn('test-uniq.jpg');

        $this->fileStorageDbMock->expects($this->any())
            ->method('checkDbUsage')
            ->willReturn($dbUsage);

        if ($dbUsage) {
            $this->mediaConfigMock->expects($this->once())
                ->method('getMediaShortUrl')
                ->with('test-uniq.jpg')
                ->willReturn('/media/test-uniq.jpg');

            $this->mediaConfigMock->expects($this->once())
                ->method('getTmpMediaShortUrl')
                ->with('test.jpg')
                ->willReturn('/media/tmp/test.jpg');

            $this->mediaConfigMock->expects($this->once())
                ->method('getTmpMediaPath')
                ->with('test.jpg')
                ->willReturn('/tmp/media/test.jpg');

            $this->mediaConfigMock->expects($this->once())
                ->method('getMediaPath')
                ->with('test-uniq.jpg')
                ->willReturn('/media/test-uniq.jpg');

            $this->fileStorageDbMock->expects($this->once())
                ->method('renameFile')
                ->with('/media/tmp/test.jpg', '/media/test-uniq.jpg');

            $this->mediaDirectoryMock->expects($this->exactly(2))
                ->method('delete')
                ->withConsecutive(['/tmp/media/test.jpg'], ['/media/test-uniq.jpg']);
        } else {
            $this->mediaDirectoryMock->expects($this->once())
                ->method('renameFile')
                ->with('/tmp/media/test.jpg', '/media/test-uniq.jpg');

            $this->mediaConfigMock->expects($this->once())
                ->method('getTmpMediaPath')
                ->with('test.jpg')
                ->willReturn('/tmp/media/test.jpg');

            $this->mediaConfigMock->expects($this->once())
                ->method('getMediaPath')
                ->with('test-uniq.jpg')
                ->willReturn('/media/test-uniq.jpg');
        }

        $this->subject->moveImageFromTmp('test.jpg.tmp');
    }

    /**
     * @throws LocalizedException
     * @throws ReflectionException
     */
    public function testProcessDeletedImages(): void
    {
        $this->subject = $this->createPartialMock(
            Gallery::class,
            ['canDeleteImage', 'deleteMediaAttributeValues', 'removeDeletedImages']
        );

        $this->setPropertyValues(
            $this->subject,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock,
                'fileStorageDb' => $this->fileStorageDbMock
            ]
        );

        $productMock = $this->createMock(Product::class);

        $images = [
            [
                'value_id' => '1',
                'file' => '/one.jpg'
            ],
            [
                'removed' => true,
                'file' => '/two.jpg'
            ],
            [
                'removed' => true,
                'value_id' => '3',
                'file' => '/three.jpg'
            ],
            [
                'removed' => true,
                'value_id' => '4',
                'file' => '/four.jpg'
            ]
        ];

        $this->subject->expects($this->exactly(2))
            ->method('canDeleteImage')
            ->withConsecutive(['/three.jpg'], ['/four.jpg'])
            ->willReturnOnConsecutiveCalls(true, false);

        $this->subject->expects($this->once())
            ->method('deleteMediaAttributeValues')
            ->with($productMock, ['/three.jpg', '/four.jpg']);

        $this->resourceModelMock->expects($this->once())
            ->method('deleteGallery')
            ->with(['3', '4']);

        $this->subject->expects($this->once())
            ->method('removeDeletedImages')
            ->with(['three.jpg']);

        $this->subject->processDeletedImages($productMock, $images);
    }

    /**
     * @return array
     */
    public function getProcessExistingImagesData(): array
    {
        return [
            [[
                'value_id' => '1',
                'label' => 'one',
                'position' => 0,
                'disabled' => 0
            ]],
            [[
                'value_id' => '1',
                'label' => 'two',
                'position' => 0,
                'disabled' => 0
            ]]
        ];
    }

    /**
     * @param $extantData
     *
     * @dataProvider getProcessExistingImagesData
     */
    public function testProcessExistingImages($extantData): void
    {
        $productMock = $this->createPartialMock(
            Product::class,
            ['getStoreId']
        );

        $images = [
            [
                'value_id' => '1',
                'label' => 'one',
                'position' => 0,
                'disabled' => 0
            ]
        ];

        $productMock->expects($this->any())
            ->method('getStoreId')
            ->willReturn(1);

        $this->resourceModelMock->expects($this->once())
            ->method('loadDataFromTableByValueId')
            ->with(GalleryResource::GALLERY_VALUE_TABLE, ['1'], 1)
            ->willReturn([$extantData]);

        if ($extantData['label'] == 'two') {
            $this->resourceModelMock->expects($this->once())
                ->method('updateGalleryValueInStore')
                ->with([
                    'value_id' => '1',
                    'label' => 'one',
                    'position' => 0,
                    'disabled' => 0,
                    'store_id' => 1
                ]);
        }

        $this->subject->processExistingImages($productMock, $images);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessMediaAttributes(): void
    {
        $this->subject = $this->createPartialMock(
            Gallery::class,
            ['processMediaAttribute', 'processMediaAttributeLabel', 'getMediaAttributeCodes']
        );

        $this->setPropertyValues(
            $this->subject,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock,
                'fileStorageDb' => $this->fileStorageDbMock
            ]
        );

        $productMock = $this->createMock(Product::class);

        $this->subject->expects($this->once())
            ->method('getMediaAttributeCodes')
            ->with()
            ->willReturn(['test', 'image']);

        $this->subject->expects($this->exactly(2))
            ->method('processMediaAttribute')
            ->withConsecutive([$productMock, 'test', ['clear'], ['new']], [$productMock, 'image', ['clear'], ['new']]);

        $this->subject->expects($this->once())
            ->method('processMediaAttributeLabel')
            ->with($productMock, 'image', ['clear'], ['new'], ['exist']);

        $this->subject->processMediaAttributes($productMock, ['exist'], ['new'], ['clear']);
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessNewImages(): void
    {
        $this->subject = $this->createPartialMock(
            Gallery::class,
            ['processNewImage']
        );

        $this->setPropertyValues(
            $this->subject,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock,
                'fileStorageDb' => $this->fileStorageDbMock,
                'metadata' => $this->metadataMock
            ]
        );

        $productMock = $this->createMock(Product::class);

        $this->subject->expects($this->once())
            ->method('processNewImage')
            ->with($productMock, [
                'value_id' => '1',
                'label' => 'one',
                'position' => 1,
                'disabled' => 0
            ])
            ->willReturn([
                'value_id' => '1',
                'label' => 'one',
                'position' => 1,
                'disabled' => 0
            ]);

        $productMock->expects($this->once())
            ->method('getStoreId')
            ->willReturn(1);

        $this->metadataMock->expects($this->exactly(2))
            ->method('getLinkField')
            ->willReturn('link_field');

        $productMock->expects($this->once())
            ->method('getData')
            ->with('link_field')
            ->willReturn('42');

        $this->resourceModelMock->expects($this->once())
            ->method('insertGalleryValueInStore')
            ->with([
                'value_id' => '1',
                'label' => 'one',
                'position' => 1,
                'disabled' => 0,
                'store_id' => 1,
                'link_field' => 42
            ]);

        $images = [
            [
                'value_id' => '1',
                'label' => 'one',
                'position' => 1,
                'disabled' => 0
            ]
        ];

        $this->subject->processNewImages($productMock, $images);
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

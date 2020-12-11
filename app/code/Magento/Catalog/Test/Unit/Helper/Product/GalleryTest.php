<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Helper\Product;

use Magento\Catalog\Helper\Product\Gallery;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery as GalleryResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 */
class GalleryTest extends TestCase
{
    /**
     * @var Gallery
     */
    protected $subject;

    /**
     * @var Config|MockObject
     */
    protected $mediaConfigMock;

    /**
     * @var WriteInterface|MockObject
     */
    protected $mediaDirectoryMock;

    /**
     * @var GalleryResource|MockObject
     */
    protected $resourceModelMock;

    /**
     */
    public function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $this->mediaConfigMock = $this->createPartialMock(
            Config::class,
            ['getBaseMediaPath']
        );

        $this->mediaDirectoryMock = $this->createPartialMock(
            WriteInterface::class,
            ['isFile']
        );

        $this->resourceModelMock = $this->createPartialMock(
            GalleryResource::class,
            ['countImageUses']
        );

        $this->subject = $objectManager->getObject(
            Gallery::class,
            [
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'resourceModel' => $this->resourceModelMock
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
     * @param $returnValue
     * @throws LocalizedException
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

        $this->resourceModelMock->expects($this->once())
            ->method('countImageUses')
            ->with('test.jpg')
            ->willReturn($countImageUsesReturn);

        $actual = $this->subject->canDeleteImage('test.jpg');

        $this->assertEquals($expected, $actual);
    }
}

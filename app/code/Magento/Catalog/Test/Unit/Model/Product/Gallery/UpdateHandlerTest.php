<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\Gallery;

use PHPUnit\Framework\TestCase;

/**
 * Unit test for Catalog Product Gallery UpdateHandler
 */
class UpdateHandlerTest extends TestCase
{
    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
    }

    /**
     * @return array
     */
    public function validateDataProvider()
    {
        return [
            [0]
        ];
    }

    /**
     * @param $value
     * @dataProvider validateDataProvider
     */
    public function testExecute($value)
    {
        $this->assertEquals(1, $value);
    }
}

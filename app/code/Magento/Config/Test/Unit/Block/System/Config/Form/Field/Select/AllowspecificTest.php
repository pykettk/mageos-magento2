<?php declare(strict_types=1);
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Config\Test\Unit\Block\System\Config\Form\Field\Select;

use Magento\Config\Block\System\Config\Form\Field\Select\Allowspecific;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\Select;
use Magento\Framework\Escaper;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AllowspecificTest extends TestCase
{
    /**
     * @var Allowspecific
     */
    protected $_object;

    /**
     * @var MockObject
     */
    protected $_formMock;

    protected function setUp(): void
    {
        $testHelper = new ObjectManager($this);
        $this->_object = $testHelper->getObject(
            Allowspecific::class,
            [
                '_escaper' => $testHelper->getObject(Escaper::class)
            ]
        );
        $this->_object->setData('html_id', 'spec_element');
        $this->_formMock = $this->createPartialMock(
            Form::class,
            ['getHtmlIdPrefix', 'getHtmlIdSuffix', 'getElement']
        );
    }

    public function testGetAfterElementHtml()
    {
        $this->_formMock->expects(
            $this->once()
        )->method(
            'getHtmlIdPrefix'
        )->will(
            $this->returnValue('test_prefix_')
        );
        $this->_formMock->expects(
            $this->once()
        )->method(
            'getHtmlIdSuffix'
        )->will(
            $this->returnValue('_test_suffix')
        );

        $afterHtmlCode = 'after html';
        $this->_object->setData('after_element_html', $afterHtmlCode);
        $this->_object->setForm($this->_formMock);

        $actual = $this->_object->getAfterElementHtml();

        $this->assertStringEndsWith('</script>' . $afterHtmlCode, $actual);
        $this->assertStringStartsWith('<script type="text/javascript">', trim($actual));
        $this->assertContains('test_prefix_spec_element_test_suffix', $actual);
    }

    /**
     * @param $value
     * @dataProvider getHtmlWhenValueIsEmptyDataProvider
     */
    public function testGetHtmlWhenValueIsEmpty($value)
    {
        $this->_object->setForm($this->_formMock);

        $elementMock = $this->createPartialMock(Select::class, ['setDisabled']);

        $elementMock->expects($this->once())->method('setDisabled')->with('disabled');
        $countryId = 'tetst_county_specificcountry';
        $this->_object->setId('tetst_county_allowspecific');
        $this->_formMock->expects(
            $this->once()
        )->method(
            'getElement'
        )->with(
            $countryId
        )->will(
            $this->returnValue($elementMock)
        );

        $this->_object->setValue($value);
        $this->assertNotEmpty($this->_object->getHtml());
    }

    /**
     * @return array
     */
    public function getHtmlWhenValueIsEmptyDataProvider()
    {
        return [
            'zero' => ['1' => 0],
            'null' => ['1' => null],
            'false' => ['1' => false],
            'negative' => ['1' => -1]
        ];
    }
}

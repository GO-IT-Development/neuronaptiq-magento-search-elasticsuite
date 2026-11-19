<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * Attributes of the layered navigation of ElasticSuite and their options (SimpKo/project#324)
 *
 * ElasticSuite filters and aggregates attributes with options by the option text of the store view
 * (field option_text_<code>, e.g. "Black"), Yes/No attributes by the value (field <code>, 1 or 0). The search API
 * uses the option ids. The labels come from the attribute source in the current store, like in the ElasticSuite
 * index.
 */
class AttributeOptions
{
    public const OPTION_TEXT_PREFIX = 'option_text_';
    private const INPUT_BOOLEAN = 'boolean';

    /**
     * @var EavConfig
     */
    private EavConfig $eavConfig;

    /**
     * @var array<string, AbstractAttribute|null>
     */
    private array $attributes = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $optionTexts = [];

    /**
     * @param EavConfig $eavConfig
     */
    public function __construct(EavConfig $eavConfig)
    {
        $this->eavConfig = $eavConfig;
    }

    /**
     * Field of the attribute in the ElasticSuite request and response, null if it is no product attribute
     *
     * @param string $attributeCode
     * @return string|null
     */
    public function getFilterField(string $attributeCode): ?string
    {
        $attribute = $this->getAttribute($attributeCode);
        if ($attribute === null) {
            return null;
        }

        return $this->usesOptionTextOf($attribute) ? self::OPTION_TEXT_PREFIX . $attributeCode : $attributeCode;
    }

    /**
     * Whether the field holds option texts (option_text_<code>)
     *
     * @param string $attributeCode
     * @return bool
     */
    public function usesOptionText(string $attributeCode): bool
    {
        $attribute = $this->getAttribute($attributeCode);

        return $attribute !== null && $this->usesOptionTextOf($attribute);
    }

    /**
     * Whether it is a Yes/No attribute (field <code>, values 1 and 0)
     *
     * @param string $attributeCode
     * @return bool
     */
    public function isBoolean(string $attributeCode): bool
    {
        $attribute = $this->getAttribute($attributeCode);

        return $attribute !== null && $attribute->getFrontendInput() === self::INPUT_BOOLEAN;
    }

    /**
     * Option ids of the given option texts, an option text may belong to several options
     *
     * @param string $attributeCode
     * @param array $texts
     * @return list<string>|null Null if a text is not an option of the attribute
     * @phpstan-param array<mixed> $texts
     */
    public function getOptionIds(string $attributeCode, array $texts): ?array
    {
        $options = $this->getOptionTexts($attributeCode);
        $ids = [];
        foreach ($texts as $text) {
            $found = is_scalar($text) ? array_keys($options, (string)$text, true) : [];
            if ($found === []) {
                return null;
            }
            foreach ($found as $id) {
                $ids[] = (string)$id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Option texts by option id
     *
     * @param string $attributeCode
     * @return array<string, string>
     */
    public function getOptionTexts(string $attributeCode): array
    {
        if (!isset($this->optionTexts[$attributeCode])) {
            $texts = [];
            $attribute = $this->getAttribute($attributeCode);
            if ($attribute !== null && $attribute->usesSource()) {
                // The empty option (value '') is skipped below
                foreach ($attribute->getSource()->getAllOptions() as $option) {
                    $value = $option['value'] ?? null;
                    $label = $option['label'] ?? null;
                    $hasLabel = is_scalar($label) || $label instanceof \Stringable;
                    if (is_scalar($value) && (string)$value !== '' && $hasLabel) {
                        $texts[(string)$value] = (string)$label;
                    }
                }
            }
            $this->optionTexts[$attributeCode] = $texts;
        }

        return $this->optionTexts[$attributeCode];
    }

    /**
     * Whether ElasticSuite uses the option text field (attributes with options except Yes/No)
     *
     * @param AbstractAttribute $attribute
     * @return bool
     */
    private function usesOptionTextOf(AbstractAttribute $attribute): bool
    {
        return $attribute->getFrontendInput() !== self::INPUT_BOOLEAN && $attribute->usesSource();
    }

    /**
     * Product attribute by code, null if it does not exist
     *
     * @param string $attributeCode
     * @return AbstractAttribute|null
     */
    private function getAttribute(string $attributeCode): ?AbstractAttribute
    {
        if (!array_key_exists($attributeCode, $this->attributes)) {
            try {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
                $this->attributes[$attributeCode] = $attribute->getId() ? $attribute : null;
            } catch (\Exception $e) {
                $this->attributes[$attributeCode] = null;
            }
        }

        return $this->attributes[$attributeCode];
    }
}

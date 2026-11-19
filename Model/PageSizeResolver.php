<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Model;

use Magento\Catalog\Helper\Product\ProductList;
use Magento\Framework\App\RequestInterface;

/**
 * Resolves the page size of the product list like the toolbar (URL parameters product_list_mode/_limit,
 * otherwise the default of the view mode)
 *
 * ElasticSuite queries the total with a separate request without page size, which can come before the request
 * for the page. With the same page size, both are answered by a single API request.
 */
class PageSizeResolver
{
    /**
     * @var ProductList
     */
    private ProductList $productListHelper;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @param ProductList $productListHelper
     * @param RequestInterface $request
     */
    public function __construct(ProductList $productListHelper, RequestInterface $request)
    {
        $this->productListHelper = $productListHelper;
        $this->request = $request;
    }

    /**
     * Returns the page size of the product list of the current request
     *
     * @return int
     */
    public function getPageSize(): int
    {
        $modes = $this->productListHelper->getAvailableViewMode() ?: [];
        $mode = (string)$this->request->getParam('product_list_mode', '');
        if (!isset($modes[$mode])) {
            $mode = (string)$this->productListHelper->getDefaultViewMode($modes);
        }

        $limit = (string)$this->request->getParam('product_list_limit', '');
        // "all" is not supported, the API returns pages
        if ($limit !== '' && ctype_digit($limit) && isset($this->productListHelper->getAvailableLimit($mode)[$limit])) {
            return (int)$limit;
        }

        return $this->productListHelper->getDefaultLimitPerPageValue($mode);
    }
}

<?php
namespace Minphp\Pagination\Tests;

use Minphp\Pagination\Pagination;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Minphp\Pagination\Pagination
 */
class PaginationTest extends TestCase
{
    /**
     * @covers ::__construct
     * @covers ::mergeArrays
     * @uses \Minphp\Pagination\Pagination::setGet
     */
    public function testConstruct()
    {
        $this->assertInstanceOf('\Minphp\Pagination\Pagination', new Pagination());
    }

    /**
     * Helper: build pagination HTML and extract the page number marked as current.
     *
     * @param string $requestUri The simulated $_SERVER['REQUEST_URI']
     * @param string $paginationUri The configured URI template containing [p]
     * @param array $get The GET parameters to pass to the pagination instance
     * @param int $totalResults Total number of results
     * @param int $perPage Results per page
     * @return int|null The page number rendered as "current", or null if not found
     */
    private function getCurrentPageFromBuild(
        $requestUri,
        $paginationUri,
        array $get = [],
        $totalResults = 50,
        $perPage = 10
    ) {
        $_SERVER['REQUEST_URI'] = $requestUri;

        $pagination = new Pagination($get, [
            'total_results' => $totalResults,
            'results_per_page' => $perPage,
            'uri' => $paginationUri,
            'uri_labels' => ['page' => 'p', 'per_page' => 'pp'],
            'show' => 'always',
            'pages_to_show' => 10,
            'merge_get' => false,
        ]);
        $pagination->setOutput(true);

        $html = $pagination->build();

        // The "current" page renders as: <li class="current">\n{number}\n</li>
        if (preg_match('/<li class="current">\s*(\d+)\s*<\/li>/s', $html, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Sorting with a query string and no page segment should resolve to page 1.
     *
     * This is the primary bug scenario: widget column headers generate sort
     * links like /clients/invoices/123/open/?sort=due&order=asc with no page
     * segment. Before the fix, the query string landed in the [p] position
     * and was misinterpreted as a non-numeric value.
     *
     * @covers ::build
     * @covers ::currentPage
     * @covers ::hasPages
     * @covers ::createNavItem
     * @covers ::createLink
     * @covers ::openTag
     * @covers ::closeTag
     * @covers ::getPageUri
     * @covers ::getUri
     * @covers ::mergeArrays
     * @uses \Minphp\Pagination\Pagination::__construct
     * @uses \Minphp\Pagination\Pagination::setGet
     * @uses \Minphp\Pagination\Pagination::setOutput
     */
    public function testCurrentPageDefaultsToOneWhenQueryStringInsteadOfPageSegment()
    {
        $page = $this->getCurrentPageFromBuild(
            '/admin/clients/invoices/123/open/?sort=due&order=asc',
            '/admin/clients/invoices/123/open/[p]/'
        );

        $this->assertSame(1, $page);
    }

    /**
     * An explicit page segment with a trailing query string should be parsed correctly.
     *
     * @covers ::build
     * @covers ::currentPage
     * @covers ::hasPages
     * @covers ::createNavItem
     * @covers ::createLink
     * @covers ::openTag
     * @covers ::closeTag
     * @covers ::getPageUri
     * @covers ::getUri
     * @covers ::mergeArrays
     * @uses \Minphp\Pagination\Pagination::__construct
     * @uses \Minphp\Pagination\Pagination::setGet
     * @uses \Minphp\Pagination\Pagination::setOutput
     */
    public function testCurrentPageParsedFromPathWithQueryString()
    {
        $page = $this->getCurrentPageFromBuild(
            '/admin/clients/invoices/123/open/3/?sort=due&order=asc',
            '/admin/clients/invoices/123/open/[p]/'
        );

        $this->assertSame(3, $page);
    }

    /**
     * When the path segment is missing, the page should fall back to
     * the GET parameter if it is numeric.
     *
     * @covers ::build
     * @covers ::currentPage
     * @covers ::hasPages
     * @covers ::createNavItem
     * @covers ::createLink
     * @covers ::openTag
     * @covers ::closeTag
     * @covers ::getPageUri
     * @covers ::getUri
     * @covers ::mergeArrays
     * @uses \Minphp\Pagination\Pagination::__construct
     * @uses \Minphp\Pagination\Pagination::setGet
     * @uses \Minphp\Pagination\Pagination::setOutput
     */
    public function testCurrentPageFallsBackToGetParameter()
    {
        $page = $this->getCurrentPageFromBuild(
            '/admin/clients/invoices/123/open/',
            '/admin/clients/invoices/123/open/[p]/',
            ['p' => '4']
        );

        $this->assertSame(4, $page);
    }

    /**
     * When the page placeholder is at segment index 0, the page should still
     * be read correctly (guards against the $index == 0 falsy check).
     *
     * @covers ::build
     * @covers ::currentPage
     * @covers ::hasPages
     * @covers ::createNavItem
     * @covers ::createLink
     * @covers ::openTag
     * @covers ::closeTag
     * @covers ::getPageUri
     * @covers ::getUri
     * @covers ::mergeArrays
     * @uses \Minphp\Pagination\Pagination::__construct
     * @uses \Minphp\Pagination\Pagination::setGet
     * @uses \Minphp\Pagination\Pagination::setOutput
     */
    public function testCurrentPageWhenPlaceholderAtIndexZero()
    {
        $page = $this->getCurrentPageFromBuild(
            '2/items/',
            '[p]/items/'
        );

        $this->assertSame(2, $page);
    }

    /**
     * A non-numeric GET parameter for the page label should be ignored,
     * defaulting to page 1.
     *
     * @covers ::build
     * @covers ::currentPage
     * @covers ::hasPages
     * @covers ::createNavItem
     * @covers ::createLink
     * @covers ::openTag
     * @covers ::closeTag
     * @covers ::getPageUri
     * @covers ::getUri
     * @covers ::mergeArrays
     * @uses \Minphp\Pagination\Pagination::__construct
     * @uses \Minphp\Pagination\Pagination::setGet
     * @uses \Minphp\Pagination\Pagination::setOutput
     */
    public function testCurrentPageIgnoresNonNumericGetParameter()
    {
        $page = $this->getCurrentPageFromBuild(
            '/admin/clients/invoices/123/open/',
            '/admin/clients/invoices/123/open/[p]/',
            ['p' => 'abc']
        );

        $this->assertSame(1, $page);
    }
}

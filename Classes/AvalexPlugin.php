<?php

/*
 * This file is part of the package jweiland/avalex.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace JWeiland\Avalex;

use JWeiland\Avalex\Client\Request\BedingungenRequest;
use JWeiland\Avalex\Client\Request\DatenschutzerklaerungRequest;
use JWeiland\Avalex\Client\Request\ImpressumRequest;
use JWeiland\Avalex\Client\Request\LocalizeableRequestInterface;
use JWeiland\Avalex\Client\Request\RequestInterface;
use JWeiland\Avalex\Client\Request\WiderrufRequest;
use JWeiland\Avalex\Domain\Repository\AvalexConfigurationRepository;
use JWeiland\Avalex\Service\ApiService;
use JWeiland\Avalex\Service\LanguageService;
use JWeiland\Avalex\Utility\AvalexUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Class AvalexPlugin
 */
class AvalexPlugin
{
    /**
     * @var VariableFrontend
     */
    protected $cache;

    /**
     * @var ApiService
     */
    protected $apiService;

    /**
     * @var AvalexConfigurationRepository
     */
    protected $avalexConfigurationRepository;

    /**
     * @var array
     */
    protected $configuration = [];

    /**
     * @var LanguageService
     */
    protected $languageService;

    /**
     * @var ContentObjectRenderer
     */
    public $cObj;

    public function __construct()
    {
        $this->cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('avalex_content');
        $this->apiService = GeneralUtility::makeInstance(ApiService::class);
        $this->avalexConfigurationRepository = GeneralUtility::makeInstance(AvalexConfigurationRepository::class);

        $this->configuration = $this->avalexConfigurationRepository->findByWebsiteRoot(
            AvalexUtility::getRootForPage(),
            'uid, api_key, domain'
        );

        $this->languageService = $this->getLanguageService($this->configuration);
    }

    public function setContentObjectRenderer(ContentObjectRenderer $cObj)
    {
        $this->cObj = $cObj;
    }

    /**
     * Render plugin
     *
     * @param string $_ empty string
     * @param array $conf TypoScript configuration
     * @return string
     * @throws Exception\InvalidUidException
     */
    public function render($_, $conf)
    {
        $endpointRequest = $this->getRequestForEndpoint($conf['endpoint']);
        $cacheIdentifier = $this->getCacheIdentifier($endpointRequest);
        if ($this->cache->has($cacheIdentifier)) {
            return (string)$this->cache->get($cacheIdentifier);
        }

        $this->languageService->addLanguageToEndpoint($endpointRequest);
        $content = $this->apiService->getHtmlForCurrentRootPage(
            $endpointRequest,
            $this->configuration
        );

        if ($content !== '') {
            // set cache for successful calls only
            $content = $this->processLinks($content);
            $this->cache->set($cacheIdentifier, $content, [], 21600);
        }

        return $content;
    }

    /**
     * @param string $endpoint
     *
     * @return RequestInterface|LocalizeableRequestInterface
     */
    protected function getRequestForEndpoint($endpoint)
    {
        if (!is_string($endpoint)) {
            throw new \InvalidArgumentException(
                sprintf('The endpoint "%s" must be of type "string"!', $endpoint),
                1661512525
            );
        }

        switch ($endpoint) {
            case 'avx-datenschutzerklaerung':
                $requestClass = DatenschutzerklaerungRequest::class;
                break;
            case 'avx-impressum':
                $requestClass = ImpressumRequest::class;
                break;
            case 'avx-bedingungen':
                $requestClass = BedingungenRequest::class;
                break;
            case 'avx-widerruf':
                $requestClass = WiderrufRequest::class;
                break;
            default:
                throw new \InvalidArgumentException(
                    sprintf('The endpoint "%s" is invalid!', $endpoint),
                    1661512662
                );
        }

        return GeneralUtility::makeInstance($requestClass);
    }

    /**
     * @return string
     *
     * @throws Exception\InvalidUidException
     */
    protected function getCacheIdentifier(RequestInterface $endpointRequest)
    {
        return sprintf(
            'avalex_%s_%d_%d_%s',
            $endpointRequest->getEndpoint(),
            AvalexUtility::getRootForPage(),
            $GLOBALS['TSFE']->id,
            $this->languageService->getFrontendLocale()
        );
    }

    /**
     * @param string $content
     *
     * @return string
     */
    protected function processLinks($content)
    {
        $cObj = $this->cObj;
        $requestUrl = GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL');
        return preg_replace_callback(
            '@<a href="(?P<href>(?P<type>mailto:|#)[^"\']+)">(?P<text>[^<]+)<\/a>@',
            static function ($match) use ($cObj, $requestUrl) {
                if ($match['type'] === 'mailto:') {
                    $encrypted = $cObj->getMailTo(substr($match['href'], 7), $match['text']);
                    if (count($encrypted) === 3) {
                        // TYPO3 >= 11
                        $html = '<a href="' . $encrypted[0] . '" '
                            . GeneralUtility::implodeAttributes($encrypted[2], true)
                            . '>' . $encrypted[1] . '</a>';
                    } else {
                        $html = '<a href="' . $encrypted[0] . '">' . $encrypted[1] . '</a>';
                    }
                    return $html;
                }
                return (string)str_replace($match['href'], $requestUrl . $match['href'], $match[0]);
            },
            $content
        );
    }

    /**
     * @param array $configuration
     *
     * @return LanguageService
     */
    protected function getLanguageService(array $configuration)
    {
        return GeneralUtility::makeInstance(LanguageService::class, $configuration);
    }
}

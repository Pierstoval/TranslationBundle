<?php

namespace Pierstoval\Bundle\TranslationBundle\Translation;

use Doctrine\ORM\EntityManager;
use Pierstoval\Bundle\TranslationBundle\Entity\Translation;

use Symfony\Bundle\FrameworkBundle\Translation\Translator as BaseTranslator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Translation\MessageSelector;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Class Translator
 * Project Pierstoval
 *
 * @author Pierstoval
 * @version 1.0 08/01/2014
 */
class Translator extends BaseTranslator implements TranslatorInterface {

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var Translation[]
     */
    private $translationsToPersist;

    /**
     * Contains all translations which are recovered from database
     * This attribute is static because it will force the class to always
     * have the same catalogue through the all application, to avoid too many
     * database queries.
     * @var array
     */
    private static $catalogue = array();

    /**
     * Override the native message selector to be able to use it for `transchoice` method
     * @var MessageSelector
     */
    private $selector;

    /**
     * @var bool
     */
    protected $hasToBeFlushed = false;
    /**
     * @var bool
     */
    protected $flushed = false;

    /** @var EntityManager $_em */
    protected $_em;

    /**
     * @param ContainerInterface $container
     * @param MessageSelector    $selector
     * @param array              $loaderIds
     * @param array              $options
     */
    function __construct($container, MessageSelector $selector, $loaderIds = array(), array $options = array()) {
        parent::__construct($container, $selector, $loaderIds, $options);

        $this->selector = $selector ?: new MessageSelector();

        $this->_em = $this->container->get('doctrine')->getManager();
    }

    /**
     * @see Translator::trans()
     */
    public function translate($id, array $parameters = array(), $domain = null, $locale = null) {
        return $this->trans($id, $parameters, $domain, $locale);
    }

    /**
     * Returns an array with locales.
     * Keys = locales
     * Values = public languages names
     * @return array
     */
    public function getLangs() {
        return $this->container->getParameter('pierstoval_translation.locales');
    }

    /**
     * Persists all translations in the $translationsToPersist attribute,
     * then flushes the manager and clears all translations to be persisted
     * @return $this
     */
    public function flushTranslations() {
        if ($this->hasToBeFlushed && !$this->flushed) {
            foreach ($this->translationsToPersist as $translation) {
                $this->_em->persist($translation);
            }
            $this->_em->flush();
            $this->_em->clear();
            $this->translationsToPersist = array();
            $this->flushed = true;
        }
        return $this;
    }

    /**
     * In case of, flush is launched anytime the object is destructed.
     * This allows flushing even when there is any kind of error, or when the listener is not triggered.
     */
    public function __destruct(){
        $this->flushTranslations();
    }

    /**
     * Searches in native Symfony translation system if a translations exists for given source
     *
     * @param string $locale
     * @param string $source
     * @param string $domain
     * @return string|null
     */
    public function findInNativeCatalogue($locale, $source, $domain) {
        if (!isset($this->catalogues[$locale])) {
            // Loads native catalogue
            $this->loadCatalogue($locale);
        }
        return $this->catalogues[$locale]->has($source, $domain)
            && trim($this->catalogues[$locale]->get($source, $domain))
             ? $this->catalogues[$locale]->get($source, $domain)
             : null;
    }

    /**
    * {@inheritdoc}
    */
    public function trans($id, array $parameters = array(), $domain = null, $locale = null){

        $translation = $this->getTranslation($id, $domain, $locale);

        return strtr($translation, $parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function transChoice($id, $number, array $parameters = array(), $domain = null, $locale = null) {

        $translation = $this->getTranslation($id, $domain, $locale);

        return strtr($this->selector->choose($translation, (int) $number, $locale), $parameters);
    }

    /**
     * Finds a translation, first, in the native catalogue.
     * Then, searches for it in the database.
     * If the element is still not found, it will persist a new "dirty" Translation object in the database.
     * @param mixed $id
     * @param string $domain
     * @param string $locale
     * @return string
     */
    protected function getTranslation($id, $domain, $locale)
    {
        if (
            !$id
            || (is_string($id) && !trim($id))
            || is_numeric($id)
            || (is_object($id) && method_exists($id, '__toString') && !trim($id->__toString()))
        ) {
            // Avoid translating empty things
            return $id;
        }

        if (null === $locale) {
            $locale = $this->getLocale();
        } else {
            $this->assertValidLocale($locale);
        }
        if (!$domain) { $domain = 'messages'; }

        // Récupère la traduction dans le catalogue de Symfony2 natif
        $translation = $this->findInNativeCatalogue($locale, $id, $domain);

        if (null === $translation) {

            // Génère le catalogue BDD à partir de la locale et du domaine
            $this->loadDbCatalogue($locale, $domain);

            $token = md5($id.'_'.$domain.'_'.$locale);

            /** @var Translation $translation */
            $translation = $this->findToken($token);

            if ($translation) {
                if ($translation->getTranslation()) {
                    $translation = $translation->getTranslation();
                } else {
                    $translation = $id;
                }
            } else {
                $translation = new Translation();
                $translation
                    ->setToken($token)
                    ->setSource($id)
                    ->setDomain($domain)
                    ->setLocale($locale);
                $this->hasToBeFlushed = true;
                $this->translationsToPersist[] = $translation;
                static::$catalogue[$locale][$domain][$token] = $translation;
                $translation = $id;
            }
        }

        return $translation;
    }

    /**
     * Searches for a token in the static catalogue and returns it if found.
     * @param string $token
     * @return null|Translation
     */
    protected function findToken($token) {
        $catalogue = static::$catalogue;
        foreach ($catalogue as $locale_catalogue) {
            foreach ($locale_catalogue as $domain_catalogue) {
                if (isset($domain_catalogue[$token])) {
                    return $domain_catalogue[$token];
                }
            }
        }
        return null;
    }

    /**
     * Loads and populates a catalogue from the database.
     * @param string $locale
     * @param string $domain
     */
    protected function loadDbCatalogue($locale, $domain){
        $catalogue = static::$catalogue;

        if (!isset($catalogue[$locale][$domain])) {
            $translations = $this->_em
                ->getRepository('PierstovalTranslationBundle:Translation')
                ->findBy(array('locale'=>$locale,'domain'=>$domain));

            if ($translations) {
                foreach ($translations as $translation) {
                    /** @var Translation $translation */
                    static::$catalogue[$locale][$translation->getDomain()][$translation->getToken()] = $translation;
                }
            }
        }
    }

}

<?php

namespace Captioning\Format;

use Captioning\Cue;
use Captioning\File;
use Captioning\FileInterface;

class TtmlFile extends File
{
    const TIMEBASE_MEDIA = 0;
    const TIMEBASE_SMPTE = 1;
    const TIMEBASE_CLOCK = 2;

    private $timeBase;

    private $tickRate;

    private $styles = [];

    private $regions = [];

    private $defaultLang = 'en';

    private $title = '';
    private $copyRight = '';

    private $languages = [];

    /**
     * @param string $_timeBase
     * @return TtmlFile
     */
    public function setTimeBase(string $_timeBase): TtmlFile
    {
        $matchingTable = [
            'media' => self::TIMEBASE_MEDIA,
            'smpte' => self::TIMEBASE_SMPTE,
            'clock' => self::TIMEBASE_CLOCK,
        ];

        if (isset($matchingTable[$_timeBase])) {
            $_timeBase = $matchingTable[$_timeBase];
        }

        if (!in_array($_timeBase, [self::TIMEBASE_MEDIA, self::TIMEBASE_SMPTE, self::TIMEBASE_CLOCK], true)) {
            throw new \InvalidArgumentException;
        }

        $this->timeBase = $_timeBase;

        return $this;
    }

    public function getTimeBase()
    {
        return $this->timeBase;
    }

    /**
     * @param string $_tickRate
     */
    public function setTickRate(string $_tickRate)
    {
        $this->tickRate = $_tickRate;
    }

    public function getTickRate()
    {
        return $this->tickRate;
    }

    /**
     * @return TtmlFile
     */
    public function parse(): FileInterface
    {
        $xml = simplexml_load_string($this->fileContent);

        // parsing headers
        $this->setTimeBase((string)$xml->attributes('ttp', true)->timeBase);
        $this->setTickRate((string)$xml->attributes('ttp', true)->tickRate);

        $head = $xml->head;

        // parsing styles
        foreach ($head->styling->style as $style) {
            $styleData = $this->parseAttributes($style);
            $this->styles[$styleData['id']] = $styleData;
        }

        // parsing regions
        $regions = $head->layout->region;
        foreach ($regions as $region) {
            $regionData = $this->parseAttributes($region);
            $this->regions[$regionData['id']] = $regionData;

            if ($region->style) {
                $regionAttr = [];
                foreach ($region->style as $regionStyle) {
                    $regionAttr = array_merge($regionAttr, $this->parseAttributes($regionStyle));
                }
                $this->regions[$regionData['id']] = array_merge($this->regions[$regionData['id']], $regionAttr);
            }
        }

        // parsing cues
        $this->parseCues($xml->body);

        return $this;
    }

    protected function getNewTtmlDocument(): \SimpleXMLElement
    {
        $baseXml = <<< EOFTT
<?xml version="1.0" encoding="UTF-8"?>
<tt xml:lang="{$this->defaultLang}" xmlns="http://www.w3.org/ns/ttml">
    <head>
    <metadata xmlns:ttm="http://www.w3.org/ns/ttml#metadata">
      <ttm:title>{$this->title}</ttm:title>
      <ttm:copyright>{$this->copyRight}</ttm:copyright>
</metadata>
</head>
<body>

</body>    
</tt>
EOFTT;
        $tt = simplexml_load_string($baseXml);
        if (!$this->copyRight) {
            $tt->head->metadata->children('ttm', true)->copyright = "Copyright " . date('Y');
        }
        // TODO: Add Regions

        // TODO: Add Styling

        return $tt;
    }

    /**
     * @param int $_from
     * @param int $_to
     * @return TtmlFile
     */
    public function buildPart(int $_from, int $_to): FileInterface
    {
        $tt = $this->getNewTtmlDocument();
        $this->sortCues();
        if ($_from < 0 || $_from >= $this->getCuesCount()) {
            $_from = 0;
        }

        if ($_to < 0 || $_to >= $this->getCuesCount()) {
            $_to = $this->getCuesCount() - 1;
        }

        $langDivs = [];

        foreach ($this->languages as $language) {
            $div = $tt->body->addChild('div');
            $div->addAttribute('xml:lang', $language, 'xml');
            $langDivs[strtolower($language)] = $div;
            $div->addChild('p');
        }

        for ($j = $_from; $j <= $_to; $j++) {
            $cue = $this->getCue($j);
            if ($cue instanceof TtmlCue) {
                $lang = $cue->getLang() ?? $this->defaultLang;
            } else {
                $lang = $this->defaultLang;
            }

            if ($div = $langDivs[strtolower($lang)] ?? null) {
                $p = $div->addChild('p', htmlspecialchars($cue->getText()));
                $p->addAttribute('begin', $this->cueTime($cue->getStartMS(), $cue->getStart()));
                $p->addAttribute('end', $this->cueTime($cue->getStopMS(), $cue->getStop()));
            }
        }

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $doc->loadXML($tt->asXML());
        $this->fileContent = $doc->saveXML();
        return $this;
    }

    private function cueTime($timeMs, $time): string {
        return sprintf("%0.4fs", $timeMs ?? $time / 1000.0);
    }

    /**
     * @param TtmlCue $_mixed
     * @return TtmlFile
     */
    public function addCue($_mixed, $_start = null, $_stop = null): File
    {
        if (is_object($_mixed) && get_class($_mixed) === self::getExpectedCueClass($this)) {
            if (null !== $_mixed->getStyle() && !isset($this->styles[$_mixed->getStyle()])) {
                throw new \InvalidArgumentException(sprintf('Invalid cue style "%s"', $_mixed->getStyle()));
            }
            if (null !== $_mixed->getRegion() && !isset($this->regions[$_mixed->getRegion()])) {
                throw new \InvalidArgumentException(sprintf('Invalid cue region "%s"', $_mixed->getRegion()));
            }
        }

        return parent::addCue($_mixed, $_start, $_stop);
    }

    public function getStyles()
    {
        return $this->styles;
    }

    public function getStyle($_style_id)
    {
        if (!isset($this->styles[$_style_id])) {
            throw new \InvalidArgumentException;
        }

        return $this->styles[$_style_id];
    }

    public function getRegions()
    {
        return $this->regions;
    }

    public function getRegion($_region_id)
    {
        if (!isset($this->regions[$_region_id])) {
            throw new \InvalidArgumentException;
        }

        return $this->regions[$_region_id];
    }

    private function parseAttributes($_node, $_namespace = 'tts')
    {
        $attributes = [];

        foreach ($_node->attributes($_namespace, true) as $property => $value) {
            $attributes[(string)$property] = (string)$value;
        }

        if ($_node->attributes('xml', true)->id) {
            $attributes['id'] = (string)$_node->attributes('xml', true)->id;
        }

        return $attributes;
    }

    private function parseCues($_xml)
    {
        $start = '';
        $stop = '';
        $startMS = 0;
        $stopMS = 0;
        foreach ($_xml->div->p as $p) {
            if (self::TIMEBASE_MEDIA === $this->timeBase) {
                $start   = (string)$p->attributes()->begin;
                $stop    = (string)$p->attributes()->end;
                $startMS = (int)rtrim($start, 't') / $this->tickRate * 1000;
                $stopMS  = (int)rtrim($stop, 't') / $this->tickRate * 1000;
            }

            $text = $p->asXml();

            $text = preg_replace('#^<p[^>]+>(.+)</p>$#isU', '$1', $text);

            $cue = new TtmlCue($start, $stop, $text);

            $cue->setStartMS($startMS);
            $cue->setStopMS($stopMS);
            $cue->setId((string)$p->attributes('xml', true)->id);

            if ($p->attributes()->style) {
                $cue->setStyle((string)$p->attributes()->style);
            }
            if ($p->attributes()->region) {
                $cue->setRegion((string)$p->attributes()->region);
            }

            $this->addCue($cue);
        }
    }

    /**
     * @return string
     */
    public function getDefaultLang(): string
    {
        return $this->defaultLang;
    }

    /**
     * @param string $defaultLang
     *
     * @return $this
     */
    public function setDefaultLang(string $defaultLang)
    {
        $this->defaultLang = $defaultLang;

        return $this;
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title
     *
     * @return $this
     */
    public function setTitle(string $title)
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return string
     */
    public function getCopyRight(): string
    {
        return $this->copyRight;
    }

    /**
     * @param string $copyRight
     *
     * @return $this
     */
    public function setCopyRight(string $copyRight)
    {
        $this->copyRight = $copyRight;

        return $this;
    }

    public function addCues(File $file, string $iso6391LanguageCode)
    {
        $this->languages[] = $iso6391LanguageCode;
        foreach ($file->getCues() as $cue) {
            if ($cue instanceof Cue) {
                $begin = $cue->getStartMS() ?? $cue->getStart();
                $end = $cue->getStopMS() ?? $cue->getStop();
                $ttmlCue = new TtmlCue($begin, $end, $cue->getText());
                $ttmlCue->setLang($iso6391LanguageCode);
                $this->addCue($ttmlCue);
            }
        }
    }

    /**
     * Gets a clone of this TtmlFile with only the cues in a given language
     * @param string|null $iso6391LanguageCode
     *
     * @return TtmlFile
     */
    public function getLanguageCuesAsFile(string $iso6391LanguageCode = null): TtmlFile
    {
        $ret = clone($this);
        $langCues = [];
        /** @var TtmlCue $cue */
        foreach ($this->getCues() as $cue) {
            if ($cue instanceof TtmlCue) {
                if ($cue->getLang() == $iso6391LanguageCode) {
                    $langCues[] = $cue;
                }
            }
        }
        $ret->cues = $langCues;
        return $ret;
    }
}

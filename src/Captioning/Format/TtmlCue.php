<?php

namespace Captioning\Format;

use Captioning\Cue;

class TtmlCue extends Cue
{
    private $style;

    private $id;

    private $region;

    /** @var null|string ISO 639.1 2 letter language code */
    private $lang = null;

    /**
     * Converts timecode format into milliseconds
     *
     * @param  string $_timecode timecode as string
     * @return int
     */
    public static function tc2ms(string $_timecode): int
    {
        // ttml format: 12.345s
        if (preg_match("/^([\d+.]+)s$/", $_timecode, $matches)) {
            return intval($matches[1] * 1000);
        }

        return SubripCue::tc2ms($_timecode);
    }

    /**
     * Converts milliseconds into subrip timecode format
     *
     * @param  int $_ms
     * @return string
     */
    public static function ms2tc(int $_ms, string $_separator = ',', $isHoursPaddingEnabled = true): string
    {
        return sprintf("%0.3fs", $_ms / 1000);
    }

    public function setStyle($_style): TtmlCue
    {
        $this->style = $_style;

        return $this;
    }

    public function getStyle()
    {
        return $this->style;
    }

    public function setId($_id): TtmlCue
    {
        $this->id = $_id;

        return $this;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setRegion($_region): TtmlCue
    {
        $this->region = $_region;

        return $this;
    }

    public function getRegion()
    {
        return $this->region;
    }

    /**
     * @return string|null
     */
    public function getLang()
    {
        return $this->lang;
    }

    /**
     * @param string|null $lang
     *
     * @return $this
     */
    public function setLang(string $lang)
    {
        $this->lang = $lang;

        return $this;
    }
}

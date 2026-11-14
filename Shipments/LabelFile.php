<?php

namespace Okay\Modules\Sviat\Ukrposhta\Shipments;

/** Файл ярликів для завантаження з масової дії списку: один PDF як є, кілька — одним ZIP. */
final readonly class LabelFile
{
    public function __construct(
        public string $name,
        public string $type,
        public string $bytes,
    ) {
    }

    /**
     * Labels::many() повертає два PDF, коли платники різні, а злити PDF без бібліотеки не можна. Браузер
     * на одну відповідь форми бере лише один файл, тож обидва йдуть одним ZIP (ext-zip вимагає composer.json).
     *
     * @param list<string> $pdfs
     * @param string $stamp частина імені файлу, напр. дата й час
     */
    public static function fromPdfs(array $pdfs, string $stamp): self
    {
        $stamp = (string)preg_replace('/[^\w-]/', '', $stamp);
        if (count($pdfs) === 1) {
            return new self("ukrposhta-labels-{$stamp}.pdf", 'application/pdf', $pdfs[0]);
        }
        if ($pdfs === []) {
            throw new \InvalidArgumentException('No labels to pack');
        }

        $path = tempnam(sys_get_temp_dir(), 'up-labels');
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file for labels');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create a ZIP of labels');
            }
            foreach ($pdfs as $index => $pdf) {
                $zip->addFromString("ukrposhta-labels-{$stamp}-" . ($index + 1) . '.pdf', $pdf);
            }
            $zip->close();
            $bytes = (string)file_get_contents($path);
        } finally {
            @unlink($path);
        }

        return new self("ukrposhta-labels-{$stamp}.zip", 'application/zip', $bytes);
    }
}

<?php

namespace App\Service;

/**
 * Déchiffre un PDF protégé sans mot de passe d'ouverture (cas des feuilles de match FDME : seules les
 * permissions sont restreintes) et le reconstruit en PDF classique, lisible par smalot/pdfparser.
 *
 * Gestionnaire de sécurité standard : RC4 40/128 bits (R2-R4), AES-128 (AESV2) et AES-256 (R5/R6).
 * Les flux d'objets (ObjStm) sont dépliés en objets indépendants ; la table xref est recalculée.
 */
final class PdfDechiffreur
{
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private string $pdf;
    private string $cle = '';
    private int $r = 0;
    private bool $aes = false;
    private bool $aes256 = false;
    private bool $flux = true;
    private bool $metadonnees = true;

    public static function estChiffre(string $pdf): bool
    {
        return (bool) preg_match('#/Encrypt\s+(\d+\s+\d+\s+R|<<)#', $pdf);
    }

    public static function dechiffrer(string $pdf): string
    {
        return (new self($pdf))->reconstruire();
    }

    private function __construct(string $pdf)
    {
        $this->pdf = $pdf;
    }

    private function reconstruire(): string
    {
        $objets = $this->objets();
        $trailer = $this->trailer();
        $encrypt = $this->dictEncrypt($trailer, $objets);
        $this->calculerCle($encrypt, $trailer);

        // Déchiffrement des flux, puis dépliage des flux d'objets
        $sortie = [];
        foreach ($objets as $id => $o) {
            [$num, $gen] = array_map('intval', explode(' ', $id));
            if (preg_match('#/Type\s*/XRef\b#', $o['dict']) || $o['id'] === $encrypt['id']) {
                continue;
            }
            if ($o['flux'] === null) {
                $sortie[$id] = ['dict' => $o['dict'], 'flux' => null];
                continue;
            }
            $donnees = $o['flux'];
            $exempt = !$this->flux || preg_match('#/Identity\b#', $o['dict'])
                || (!$this->metadonnees && preg_match('#/Type\s*/Metadata\b#', $o['dict']));
            if (!$exempt) {
                $donnees = $this->dechiffrerOctets($donnees, $num, $gen);
            }
            if (preg_match('#/Type\s*/ObjStm\b#', $o['dict'])) {
                foreach ($this->deplier($o['dict'], $donnees) as $n => $corps) {
                    $sortie["$n 0"] ??= ['dict' => $corps, 'flux' => null];
                }
                continue;
            }
            $sortie[$id] = ['dict' => $o['dict'], 'flux' => $donnees];
        }

        // Écriture d'un PDF classique
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $positions = [];
        foreach ($sortie as $id => $o) {
            $positions[(int) $id] = [strlen($out), (int) explode(' ', $id)[1]];
            $dict = $o['dict'];
            if ($o['flux'] !== null) {
                $dict = preg_replace('#/Length\s+\d+(\s+\d+\s+R)?#', '/Length '.strlen($o['flux']), $dict, 1);
                $out .= "$id obj\n$dict\nstream\n{$o['flux']}\nendstream\nendobj\n";
            } else {
                $out .= "$id obj\n$dict\nendobj\n";
            }
        }
        ksort($positions);
        $taille = max(array_keys($positions)) + 1;
        $xref = strlen($out);
        $out .= "xref\n0 $taille\n0000000000 65535 f \n";
        for ($i = 1; $i < $taille; ++$i) {
            $out .= isset($positions[$i]) ? sprintf("%010d %05d n \n", $positions[$i][0], $positions[$i][1]) : "0000000000 65535 f \n";
        }
        $racine = preg_match('#/Root\s+(\d+\s+\d+\s+R)#', $trailer, $m) ? $m[1] : '1 0 R';
        $info = preg_match('#/Info\s+(\d+\s+\d+\s+R)#', $trailer, $m) && isset($sortie[preg_replace('/\s+R$/', '', $m[1])]) ? " /Info {$m[1]}" : '';

        return $out."trailer\n<< /Size $taille /Root $racine$info >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** Objets indirects du fichier : « num gen » => [dict, flux brut|null]. La dernière définition l'emporte. */
    private function objets(): array
    {
        $objets = [];
        $pos = 0;
        while (preg_match('#(?<![\d.])(\d+)\s+(\d+)\s+obj\b#', $this->pdf, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $debut = $m[0][1] + strlen($m[0][0]);
            $id = $m[1][0].' '.$m[2][0];
            $finObj = strpos($this->pdf, 'endobj', $debut);
            $motFlux = $this->chercherMot('stream', $debut);
            if ($motFlux !== null && ($finObj === false || $motFlux < $finObj)) {
                $dict = trim(substr($this->pdf, $debut, $motFlux - $debut));
                $debutFlux = $motFlux + 6;
                if (substr($this->pdf, $debutFlux, 2) === "\r\n") {
                    $debutFlux += 2;
                } elseif (in_array($this->pdf[$debutFlux] ?? '', ["\n", "\r"], true)) {
                    ++$debutFlux;
                }
                $longueur = $this->longueur($dict);
                $finFlux = $longueur !== null && preg_match('#^\s*endstream#', substr($this->pdf, $debutFlux + $longueur, 20))
                    ? $debutFlux + $longueur
                    : $this->finFlux($debutFlux);
                $objets[$id] = ['id' => $id, 'dict' => $dict, 'flux' => substr($this->pdf, $debutFlux, $finFlux - $debutFlux)];
                $finObj = strpos($this->pdf, 'endobj', $finFlux);
            } else {
                $objets[$id] = ['id' => $id, 'dict' => trim(substr($this->pdf, $debut, ($finObj === false ? strlen($this->pdf) : $finObj) - $debut)), 'flux' => null];
            }
            if ($finObj === false) {
                break;
            }
            $pos = $finObj + 6;
        }

        return $objets;
    }

    private function chercherMot(string $mot, int $depuis): ?int
    {
        return preg_match('#(?<=[\s>\]])'.$mot.'(?=[\r\n])#', $this->pdf, $m, PREG_OFFSET_CAPTURE, $depuis) ? $m[0][1] : null;
    }

    private function finFlux(int $debut): int
    {
        $fin = strpos($this->pdf, 'endstream', $debut);
        if ($fin === false) {
            return strlen($this->pdf);
        }
        // retire la fin de ligne qui précède « endstream »
        if (substr($this->pdf, $fin - 2, 2) === "\r\n") {
            return $fin - 2;
        }

        return in_array($this->pdf[$fin - 1], ["\n", "\r"], true) ? $fin - 1 : $fin;
    }

    private function longueur(string $dict): ?int
    {
        if (preg_match('#/Length\s+(\d+)\s+(\d+)\s+R#', $dict, $m)) {
            return preg_match('#(?<![\d.])'.$m[1].'\s+'.$m[2].'\s+obj\s*(\d+)\s*endobj#', $this->pdf, $v) ? (int) $v[1] : null;
        }

        return preg_match('#/Length\s+(\d+)#', $dict, $m) ? (int) $m[1] : null;
    }

    /** Dictionnaire de fin de fichier : « trailer » classique ou dictionnaire du flux xref. */
    private function trailer(): string
    {
        if (preg_match_all('#trailer\s*(<<.*?>>)\s*startxref#s', $this->pdf, $m)) {
            return end($m[1]);
        }
        if (preg_match_all('#(\d+\s+\d+\s+obj\s*<<(?:(?!endobj).)*?/Type\s*/XRef.*?)stream#s', $this->pdf, $m)) {
            return end($m[1]);
        }
        throw new \RuntimeException('PDF : dictionnaire de fin introuvable.');
    }

    private function dictEncrypt(string $trailer, array $objets): array
    {
        if (preg_match('#/Encrypt\s+(\d+)\s+(\d+)\s+R#', $trailer, $m)) {
            $id = "$m[1] $m[2]";
            if (!isset($objets[$id])) {
                throw new \RuntimeException('PDF : dictionnaire de chiffrement introuvable.');
            }

            return ['id' => $id, 'dict' => $objets[$id]['dict']];
        }
        if (preg_match('#/Encrypt\s*(<<.*?>>)#s', $trailer, $m)) {
            return ['id' => '', 'dict' => $m[1]];
        }
        throw new \RuntimeException('PDF non chiffré.');
    }

    private function calculerCle(array $encrypt, string $trailer): void
    {
        $d = $encrypt['dict'];
        if (!preg_match('#/Filter\s*/Standard\b#', $d)) {
            throw new \RuntimeException('PDF : protection non standard (non prise en charge).');
        }
        $v = (int) $this->nombre($d, 'V', 0);
        $this->r = (int) $this->nombre($d, 'R', 2);
        $longueur = (int) $this->nombre($d, 'Length', 40);
        $p = (int) $this->nombre($d, 'P', 0);
        $o = $this->chaine($d, 'O');
        $u = $this->chaine($d, 'U');
        $this->metadonnees = !preg_match('#/EncryptMetadata\s+false#', $d);

        if ($v >= 4) {
            $filtre = preg_match('#/StmF\s*/(\w+)#', $d, $m) ? $m[1] : 'Identity';
            if ($filtre === 'Identity') {
                $this->flux = false;
            } else {
                $cfm = preg_match('#/'.$filtre.'\s*<<(?:(?!>>).)*?/CFM\s*/(\w+)#s', $d, $m) ? $m[1] : 'V2';
                $this->aes = in_array($cfm, ['AESV2', 'AESV3'], true);
                $this->aes256 = $cfm === 'AESV3';
                $this->flux = $cfm !== 'None';
                if (preg_match('#/'.$filtre.'\s*<<(?:(?!>>).)*?/Length\s+(\d+)#s', $d, $m) && $v === 4) {
                    $longueur = (int) $m[1] <= 32 ? (int) $m[1] * 8 : (int) $m[1]; // longueur en octets ou en bits selon les producteurs
                }
            }
        }

        if ($this->r >= 5) {
            // AES-256 : clé de fichier = UE déchiffré avec la clé intermédiaire (mot de passe utilisateur vide)
            $ue = $this->chaine($d, 'UE');
            $intermediaire = $this->hachage('', substr($u, 40, 8), '');
            $this->cle = openssl_decrypt($ue, 'aes-256-cbc', $intermediaire, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
            $this->aes = $this->aes256 = true;

            return;
        }

        $id0 = preg_match('#/ID\s*\[\s*(<[0-9A-Fa-f\s]*>|\((?:\\\\.|[^\\\\)])*\))#', $trailer, $m) ? $this->decoderChaine($m[1]) : '';
        $n = $this->r === 2 ? 5 : intdiv($longueur, 8);
        $cle = md5(self::PAD.$o.pack('V', $p & 0xFFFFFFFF).$id0.($this->r >= 4 && !$this->metadonnees ? "\xFF\xFF\xFF\xFF" : ''), true);
        if ($this->r >= 3) {
            for ($i = 0; $i < 50; ++$i) {
                $cle = md5(substr($cle, 0, $n), true);
            }
        }
        $this->cle = substr($cle, 0, $n);
    }

    /** Algorithme 2.B (R6) ou SHA-256 simple (R5). */
    private function hachage(string $motDePasse, string $sel, string $u): string
    {
        $k = hash('sha256', $motDePasse.$sel.$u, true);
        if ($this->r < 6) {
            return $k;
        }
        for ($i = 0; ; ++$i) {
            $k1 = str_repeat($motDePasse.$k.$u, 64);
            $e = openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $somme = array_sum(array_map('ord', str_split(substr($e, 0, 16)))) % 3;
            $k = hash(['sha256', 'sha384', 'sha512'][$somme], $e, true);
            if ($i >= 63 && ord($e[strlen($e) - 1]) <= $i - 31) {
                break;
            }
        }

        return substr($k, 0, 32);
    }

    private function dechiffrerOctets(string $donnees, int $num, int $gen): string
    {
        if ($this->aes256) {
            $cle = $this->cle;
        } else {
            $base = $this->cle.substr(pack('V', $num), 0, 3).substr(pack('V', $gen), 0, 2).($this->aes ? 'sAlT' : '');
            $cle = substr(md5($base, true), 0, min(strlen($this->cle) + 5, 16));
        }
        if (!$this->aes) {
            return self::rc4($cle, $donnees);
        }
        if (strlen($donnees) < 32) {
            return '';
        }
        $clair = openssl_decrypt(substr($donnees, 16), $this->aes256 ? 'aes-256-cbc' : 'aes-128-cbc', $cle, OPENSSL_RAW_DATA, substr($donnees, 0, 16));

        return $clair === false ? $donnees : $clair;
    }

    private static function rc4(string $cle, string $donnees): string
    {
        $s = range(0, 255);
        $j = 0;
        $n = strlen($cle);
        for ($i = 0; $i < 256; ++$i) {
            $j = ($j + $s[$i] + ord($cle[$i % $n])) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $i = $j = 0;
        $sortie = '';
        $longueur = strlen($donnees);
        for ($k = 0; $k < $longueur; ++$k) {
            $i = ($i + 1) & 255;
            $j = ($j + $s[$i]) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $sortie .= chr(ord($donnees[$k]) ^ $s[($s[$i] + $s[$j]) & 255]);
        }

        return $sortie;
    }

    /** Objets contenus dans un flux d'objets (déjà déchiffré). @return array<int, string> */
    private function deplier(string $dict, string $donnees): array
    {
        if (preg_match('#/FlateDecode\b#', $dict)) {
            $donnees = @gzuncompress($donnees) ?: (@gzinflate(substr($donnees, 2)) ?: '');
        }
        $n = (int) $this->nombre($dict, 'N', 0);
        $premier = (int) $this->nombre($dict, 'First', 0);
        $entete = preg_split('/\s+/', trim(substr($donnees, 0, $premier)));
        $paires = [];
        for ($i = 0; $i + 1 < count($entete) && count($paires) < $n; $i += 2) {
            $paires[] = [(int) $entete[$i], (int) $entete[$i + 1]];
        }
        $objets = [];
        foreach ($paires as $k => [$num, $decalage]) {
            $fin = isset($paires[$k + 1]) ? $paires[$k + 1][1] : strlen($donnees) - $premier;
            $objets[$num] = trim(substr($donnees, $premier + $decalage, $fin - $decalage));
        }

        return $objets;
    }

    private function nombre(string $dict, string $cle, int|float $defaut): int|float
    {
        return preg_match('#/'.$cle.'\s+(-?\d+(?:\.\d+)?)(?!\s+\d+\s+R)#', $dict, $m) ? $m[1] + 0 : $defaut;
    }

    private function chaine(string $dict, string $cle): string
    {
        if (!preg_match('#/'.$cle.'\s*(<[0-9A-Fa-f\s]*>|\((?:\\\\.|[^\\\\)])*\))#s', $dict, $m)) {
            return '';
        }

        return $this->decoderChaine($m[1]);
    }

    /** Chaîne PDF littérale « (…) » (avec échappements) ou hexadécimale « <…> » → octets. */
    private function decoderChaine(string $s): string
    {
        if ($s[0] === '<') {
            $hex = preg_replace('/\s+/', '', substr($s, 1, -1));

            return hex2bin(strlen($hex) % 2 ? $hex.'0' : $hex) ?: '';
        }
        $s = substr($s, 1, -1);
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; ++$i) {
            if ($s[$i] !== '\\') {
                $out .= $s[$i];
                continue;
            }
            $c = $s[++$i] ?? '';
            if (ctype_digit($c) && $c < '8') {
                $oct = $c;
                while (strlen($oct) < 3 && isset($s[$i + 1]) && ctype_digit($s[$i + 1]) && $s[$i + 1] < '8') {
                    $oct .= $s[++$i];
                }
                $out .= chr(octdec($oct) & 255);
            } elseif ($c === "\r") {
                if (($s[$i + 1] ?? '') === "\n") {
                    ++$i;
                }
            } elseif ($c !== "\n") {
                $out .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f"][$c] ?? $c;
            }
        }

        return $out;
    }
}

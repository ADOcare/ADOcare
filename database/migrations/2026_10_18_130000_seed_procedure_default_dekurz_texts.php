<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->defaults() as $procedureId => $text) {
            DB::table('procedures')
                ->where('id', $procedureId)
                ->update([
                    'default_dekurz_text' => $text,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach ($this->defaults() as $procedureId => $text) {
            DB::table('procedures')
                ->where('id', $procedureId)
                ->where('default_dekurz_text', $text)
                ->update([
                    'default_dekurz_text' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function defaults(): array
    {
        return [
            1 => 'Pacient prijatý do starostlivosti ADOS. Odobratá sesterská anamnéza, zhodnotený aktuálny zdravotný stav, sebestačnosť a potreby ošetrovateľskej starostlivosti.',
            2 => 'Príbuzní alebo členovia komunity poučení o zásadách starostlivosti o pacienta, režimových opatreniach a ďalšom postupe. Poučenie zamerané na: [TEXT].',
            4 => 'Vypracovaný plán komplexnej ošetrovateľskej starostlivosti na základe aktuálneho zdravotného stavu a potrieb pacienta.',
            5 => 'Vykonané priebežné zhodnotenie ošetrovateľskej starostlivosti. Plán starostlivosti aktualizovaný podľa aktuálneho zdravotného stavu pacienta. Zmeny: [TEXT].',
            6 => 'Vykonané záverečné vyhodnotenie komplexnej ošetrovateľskej starostlivosti a spracovaná záverečná správa.',
            13 => 'Pacient polohovaný podľa aktuálneho zdravotného stavu a potreby prevencie komplikácií. Poloha a tolerancia výkonu: [TEXT].',
            22 => 'Vykonaná výmena stomického setu a toaleta stómie. Stómia a okolie ošetrené, aktuálny stav: [TEXT].',
            24 => 'Aplikovaná neinjekčná liečba podľa ordinácie lekára. Podané: [TEXT]. Výkon pacient toleroval bez bezprostredných komplikácií.',
            25 => 'Aplikovaný terapeutický celotelový zábal alebo liečivý kúpeľ podľa ordinácie. Použitá terapia: [TEXT]. Pacient výkon toleroval.',
            26 => 'Liečivo aplikované podľa ordinácie lekára. Prípravok, dávka a spôsob aplikácie: [TEXT]. Bez bezprostredných komplikácií.',
            27 => 'Liečivo aplikované intravenózne podľa ordinácie lekára. Prípravok a dávka: [TEXT]. Aplikácia prebehla bez bezprostredných komplikácií.',
            28 => 'Pripravená a podaná infúzna terapia podľa ordinácie lekára. Infúzny roztok a medikácia: [TEXT]. Pacient terapiu toleroval.',
            32 => 'Vykonaná ošetrovateľská starostlivosť zameraná na prevenciu vzniku dekubitov, vrátane kontroly kože, polohovania a preventívnych opatrení. Stav kože: [TEXT].',
            33 => 'Vykonané ošetrenie dekubitu do 5 cm². Rana vyčistená a ošetrená podľa ordinácie a aktuálneho nálezu. Lokalita a stav rany: [TEXT].',
            34 => 'Vykonané ošetrenie dekubitu nad 5 cm². Rana vyčistená a ošetrená podľa ordinácie a aktuálneho nálezu. Lokalita, veľkosť a stav rany: [TEXT].',
            35 => 'Vykonaný výplach oka podľa ordinácie. Použitý roztok a aktuálny stav oka: [TEXT].',
            36 => 'Vykonaný preväz rany do 5 cm². Rana vyčistená a ošetrená podľa ordinácie a aktuálneho nálezu. Lokalita a stav rany: [TEXT].',
            37 => 'Vykonaný preväz rany nad 5 cm². Rana vyčistená a ošetrená podľa ordinácie a aktuálneho nálezu. Lokalita, veľkosť a stav rany: [TEXT].',
            39 => 'Vykonaná ošetrovateľská rehabilitácia podľa aktuálneho zdravotného stavu pacienta. Realizované cvičenia a reakcia pacienta: [TEXT].',
            45 => 'Vykonaný odber venóznej krvi podľa ordinácie lekára. Odobratý materiál určený na vyšetrenie: [TEXT]. Odber bez bezprostredných komplikácií.',
            48 => 'Vykonaný odber biologického materiálu podľa ordinácie. Druh odberu: [TEXT]. Materiál pripravený na ďalšie spracovanie.',
            49 => 'Zabezpečená doprava odobratého biologického materiálu na príslušné pracovisko.',
            50 => 'Vykonaná návšteva pacienta v domácom prostredí v pracovnom čase za účelom poskytnutia plánovanej ošetrovateľskej starostlivosti.',
            51 => 'Vykonaná návšteva pacienta v domácom prostredí mimo riadneho pracovného času za účelom poskytnutia potrebnej ošetrovateľskej starostlivosti.',
        ];
    }
};

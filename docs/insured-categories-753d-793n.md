# Poistné kategórie pre dávky 753d a 793n

## Rozsah a autoritatívne rozhrania

Implementácia sa týka výhradne:

- 753d podľa F-396/7 od 1. 4. 2026; spoločné položky podľa 751d F-370/9;
- 793n podľa F-372/9 od 1. 1. 2026 do 30. 6. 2026;
- 793n podľa F-372/10 od 1. 7. 2026;
- MU 5/15/2015 v znení účinnom od 1. 7. 2026.

Staršia verzia 793n účinná od roku 2024 sa nepoužíva. Pre obdobie, pre ktoré v registri nie je overené rozhranie, export skončí chybou; kód nehádá historický formát.

## Audit pôvodnej implementácie

| Oblasť | Pôvodný stav | Zmena |
|---|---|---|
| Pacient a poistenie | `PatientCoverage` s režimom domestic/eu/special/unclassified a jedným aktuálne upravovaným záznamom | Zachovaný model aj API; doplnená kategória, spôsob identifikácie, právny základ a potvrdenie nároku; zmena identity vytvorí novú historickú verziu |
| Formulár | Tri režimy | Päť používateľských kategórií a samostatný vykazovací režim/spôsob identifikácie |
| ÚDZS eOverenie | Tuzemské overenie a predvyplnenie | Zachované; predvyplnenie nastaví tuzemskú kategóriu a slovenský spôsob identifikácie |
| 753d | 38 polí a typ starostlivosti 850, ale vlastné rozhodovanie identifikácie a nesprávna kontrola zahraničného ID | Spoločný resolver; zahraničný režim vyprázdni RČ a používa polia 20–22; chýbajúci dátum žiadanky sa nevymýšľa |
| 793n | Iba N/O a domáci poistenec | N/O/A, E/F/G, I/J/K, historické krytie a rovnaký resolver ako 753d |
| Číslo dávky | 793n ho zadával používateľ | Automaticky `UUMMPP` pre obe dávky |
| Trasa 793n | Každý pacient sa počítal od pobočky | Prvý úsek od pobočky, ďalší od predchádzajúceho pacienta a posledný úsek späť do pobočky |
| Formát súboru | Lokálne formátovanie v každom controllery | Spoločný generátor kontroluje počet polí, zakazuje `|`/nový riadok, používa trailing `|` a CRLF |

## Matica režimov

| Kategória vo formulári | Vykazovací režim | Identifikácia | Povinné údaje | Nová / opravná / aditívna |
|---|---|---|---|---|
| Tuzemský | domestic | slovak_identifier | slovenská vykazujúca poisťovňa, RČ alebo pridelený BIČ | N / O / A |
| EÚ/EEA/Švajčiarsko s nárokom | eu | foreign_triad | vykazujúca poisťovňa, štát, zahraničné ID, M/F, potvrdený nárokový doklad | E / F / G |
| Mimo EÚ – RS/MK/ME s príslušným formulárom | eu | foreign_triad | rovnaká trojica a jeden z overených typov zmluvného formulára | E / F / G |
| Mimo EÚ bez potvrdeného nároku | unclassified | incomplete | konkrétny režim a právny základ ešte chýbajú | export blokovaný |
| Bezdomovec podľa § 9 ods. 4 | special | slovak_identifier | BIČ, special_category=homeless, právny základ a potvrdený nárok | I / J / K |
| Iné | special alebo explicitne určený režim | podľa nároku | konkrétny podtyp, právny základ, potvrdenie a použiteľný identifikátor | podľa potvrdeného režimu |
| Dočasný slovenský preukaz bez RČ | podľa skutočného nároku | foreign_triad | štát, ID, M/F; druh/číslo karty sa nepoužijú ako ID | podľa režimu, nie podľa názvu karty |

Matica je spoločná pre 753d aj 793n. Pri `foreign_triad` ostáva položka RČ/BIČ prázdna. Pri `slovak_identifier` ostávajú všetky tri zahraničné položky prázdne.

## Polia a štruktúra

### 753d

- identifikácia: 9 polí;
- záhlavie: 8 polí;
- telo: 38 polí;
- typ starostlivosti ADOS: 850;
- zahraničná trojica je v položkách 20–22 tela;
- odosielateľ je v položkách 17–19;
- dátum žiadanky je položka 23 a nevypĺňa sa náhradným dátumom výkonu.

### 793n

- identifikácia: 9 polí;
- záhlavie: 7 polí;
- telo: 23 polí;
- typ prepravy: ADOS;
- počet prepravených pre ADOS: 0;
- zahraničná trojica je v položkách 21–23;
- odosielateľ je v položkách 18–20;
- každý úsek má samostatné číslo jazdy; opravná veta zachováva pravidlo charakteru, no stabilita čísla jazdy voči pôvodnej odovzdanej dávke vyžaduje perzistentný snapshot jazdy (pozri neoverené body).

## Testovacie profily

Fixtures sú v `tests/Fixtures/InsuredProfiles.php`. Všetky identifikátory sú syntetické a nesmú sa odosielať do eOverenie ani poisťovniam.

| Profil | Očakávaný režim | 753d | 793n |
|---|---|---|---|
| Jana Novotná | domestic/slovak_identifier | N | N |
| Martin Kováč | domestic/slovak_identifier | N | N |
| Petra Svobodová | eu/foreign_triad | E | E |
| Anna Müllerová | eu/foreign_triad | E | E |
| Adam Horváth | eu/foreign_triad, evidované RČ sa ignoruje | E | E |
| Milan Petrović | eu/foreign_triad, SRB/SK 111 potvrdené | E | E |
| Oleksandr Melnyk | incomplete | blokované | blokované |
| Peter Bielik | special/slovak_identifier | I | I |
| Eva Poláková | special/slovak_identifier | I | I |
| Lucia Benešová | eu/foreign_triad | E | E |

## Automatické testy

Pripravené sú:

- dátovo riadené testy všetkých desiatich profilov pre oba typy dávok;
- test prázdneho RČ pri zahraničnej trojici;
- test prázdnej trojice pri slovenskom identifikátore;
- test N/O/A → N/O/A, E/F/G a I/J/K;
- negatívne testy chýbajúceho štátu, ID, pohlavia, neplatného zmluvného dokladu a neznámeho režimu;
- test presného počtu polí, trailing oddeľovača, CRLF a odmietnutia posunu polí;
- test výberu verzie rozhrania podľa dátumu;
- nezávislé golden files pre kompletnú 753d zahraničnú a 793n tuzemskú vetu.

V odovzdanom čiastkovom archíve chýba `composer.json`, `artisan`, vendor balíky aj PHP runtime. Testy a migrácie preto v tomto prostredí nebolo možné vykonať. Po vložení zmien do úplného projektu treba spustiť:

```bash
herd php artisan migrate --env=testing
herd php artisan test --testsuite=Unit
herd php artisan test
npm run build
```

## Neoverené a zmluvne závislé pravidlá

- Konkrétne zmluvné kódy pobočiek poisťovní a ceny výkonov ostávajú v existujúcej konfigurácii/cenníkoch projektu.
- Projekt neobsahuje zmluvné prílohy poisťovní, preto neboli doplnené poisťovňou špecifické hodnoty.
- Stabilné číslo jazdy pri F/J voči pôvodne odovzdanej 793n potrebuje uložený snapshot pôvodnej jazdy; súčasný kilometrový dokument ho historicky neukladal. Kód nevymýšľa pôvodné číslo.
- Pole identifikátora NZIS v 753d projekt v zdrojových údajoch nemá. Nevytvára sa zástupná hodnota; pred ostrým odoslaním treba potvrdiť, kedy ho konkrétny tok ADOS musí mať a odkiaľ sa získava.
- Automatický výpočet trasy používa existujúcu routovaciu službu. Ak zmluva poisťovne určuje inú metodiku zaokrúhľovania kilometrov, treba ju nakonfigurovať osobitne.
- Úspešné vytvorenie textového súboru nie je potvrdením prijatia poisťovňou.

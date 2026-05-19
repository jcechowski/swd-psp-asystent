#!/usr/bin/env python3
"""
Silnik Reguł SWD PSP - Rules Engine for Polish State Fire Service
Decision Support System (System Wspomagania Decyzji PSP).

Moduł realizuje:
1. Struktury danych: Karta Zdarzenia (KZ) + Informacja ze Zdarzenia (IzZ)
2. Bazę reguł (Rule Base) - graf zależności logicznych
3. Walidator krzyżowy KZ ↔ IzZ (Cross-Validator)
4. System podpowiedzi "w locie" (Suggestion Engine)
5. Testy: green path + red path

Oparte na: "Zasady ewidencjonowania zdarzeń w SWD PSP od 1.01.2025"
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import datetime
from enum import Enum
from typing import Optional

# ============================================================================
# CZĘŚĆ 1 - ENUMERACJE I KATALOGI
# ============================================================================


class RodzajZdarzenia(str, Enum):
    """Rodzaj zdarzenia z Karty Zdarzenia (sekcja II.B)."""
    POZAR = "P"
    MIEJSCOWE_ZAGROZENIE = "MZ"
    FALSZYWY_ALARM = "AF"
    CWICZENIA = "CW"
    WYJAZD_GOSPODARCZY = "WG"
    ZABEZPIECZENIE_REJONU = "PZR"
    ZDARZENIE_KG = "WKG"
    BLAD = "BL"


class WielkoscPozaru(str, Enum):
    MALY = "P/M"
    SREDNI = "P/Ś"
    DUZY = "P/D"
    BARDZO_DUZY = "P/BD"


class WielkoscMZ(str, Enum):
    MALE = "MZ/M"
    LOKALNE = "MZ/L"
    SREDNIE = "MZ/Ś"
    DUZE = "MZ/D"
    GIGANTYCZNE = "MZ/GIK"


class RodzajMZ(str, Enum):
    """Rodzaj miejscowego zagrożenia (pkt 4.2 IzZ)."""
    SILNE_WIATRY = "silne wiatry"
    PRZYBORY_WOD = "przybory wód"
    OPADY_SNIEGU = "opady śniegu"
    OPADY_DESZCZU = "opady deszczu"
    INFRASTRUKTURA_KOMUNALNA = "infrastruktury komunalnej"
    CHEMICZNE = "chemiczne"
    EKOLOGICZNE = "ekologiczne"
    RADIOLOGICZNE = "radiologiczne"
    BUDOWLANE = "budowlane"
    MEDYCZNE = "medyczne"
    TRANSPORT_DROGOWY = "w transporcie drogowym"
    TRANSPORT_KOLEJOWY = "w transporcie kolejowym"
    TRANSPORT_LOTNICZY = "w transporcie lotniczym"
    NA_OBSZARACH_WODNYCH = "na obszarach wodnych"


class KlasyfikacjaAF(str, Enum):
    ZLOSLIWY = "złośliwy"
    W_DOBREJ_WIERZE = "w dobrej wierze"
    Z_INSTALACJI_WYKRYWANIA = "z instalacji wykrywania"


class PodrodzajP(str, Enum):
    """Podrodzaj zdarzenia - pożary (Tabela 1A)."""
    INNE = "P - Inne pożary"
    UZYTECZNOSC_PUBLICZNA = "P - Instytucje, obiekty użyteczności publicznej"
    GOSPODARCZE_ROLNICZE = "P - Obiekty gospodarcze i inne rolnicze"
    MAGAZYNOWE = "P - Obiekty magazynowe, place składowe"
    MIESZKALNE = "P - Obiekty mieszkalne"
    PRODUKCYJNE = "P - Obiekty produkcyjne, instalacje technologiczne, rurociągi, urządzenia"
    SMIETNIKI = "P - Śmietniki, wysypiska"
    TRANSPORT_DROGOWY = "P - Środki transportu drogowego"
    TRANSPORT_KOLEJOWY = "P - Środki transportu kolejowego"
    TRANSPORT_LOTNICZY = "P - Środki transportu lotniczego"
    TRANSPORT_WODNY = "P - Środki transportu wodnego"
    TRAWY_LASY = "P - Trawy, torfowiska, lasy, pola, stogi"


class PodrodzajMZ(str, Enum):
    """Podrodzaj zdarzenia - miejscowe zagrożenia (Tabela 1B)."""
    ATMOSFERYCZNE = "MZ - Atmosferyczne"
    BUDOWLANE = "MZ - Budowlane"
    CHEMICZNE = "MZ - Chemiczne"
    DROGOWE = "MZ - Drogowe"
    INNE = "MZ - Inne MZ"
    KOLEJOWE = "MZ - Kolejowe"
    KOLIZJA = "MZ - Kolizja"
    LOTNICZE = "MZ - Lotnicze"
    PALENIE_OGNISK = "MZ - Palenie ognisk w miejscach niedozwolonych"
    PODEJRZENIE_LADUNKU = "MZ - Podejrzenie podłożenia ładunku"
    POMOC_INNYM = "MZ - Pomoc innym służbom"
    POMOC_POLICJI = "MZ - Pomoc Policji"
    POMOC_PRM = "MZ - Pomoc PRM"
    POMOC_OTWARCIE = "MZ - Pomoc w otwarciu mieszkania, podejrzenie zgonu"
    POSZUKIWANIE = "MZ - Poszukiwanie osób zaginionych"
    PROBA_SAMOBOJCZA = "MZ - Próba samobójcza"
    SANITARNO_EPIDEM = "MZ - Sanitarno-epidemiologiczne"
    TOPIENIE = "MZ - Topienie się"
    WODNE = "MZ - Wodne"
    WYPADEK = "MZ - Wypadek"
    ZABEZP_LADUNKU = "MZ - Zabezpieczenie ładunku"
    ZWIERZETA = "MZ - Zwierzęta"


# Flagi zdarzeń (Tabela 2) - podzbiór najważniejszych
FLAGI_ZDARZEN = {
    "do_wiadomosci_kw", "do_wiadomosci_kg",
    "dzialania_miedzynarodowe", "ewakuacja_osob",
    "gios_wios", "izrm", "lpr_169", "lpr_kswl",
    "nieprzejezdnosc_dk_s_a",
    "obiekt_gospodarczy", "obiekt_mieszkalny", "obiekty_sakralne_zabytki",
    "ofiara_smiertelna", "osoba_ranna",
    "placowka_dyplomatyczna", "plama_oleju",
    "pomoc_innym_sluzbom", "pomoc_policji", "pomoc_prm",
    "pompowanie_wody", "pozar_lasu", "pozar_pol", "pozar_trawy",
    "przybory_wod", "silne_wiatry",
    "substancja_biologiczna", "substancja_chemiczna",
    "substancja_radiologiczna", "tlenek_wegla",
    "udzial_dronow", "udzial_psow", "udzial_smiglowcow",
    "usuwanie_owadow", "usuwanie_sopli",
    "uszkodzony_dach", "vip_sop", "wiatolomy",
    "wypadek_pojazdu_osp", "wypadek_pojazdu_psp", "wypadek_ratownika",
    "wysypiska_skladowiska", "zabezp_imprezy_masowej",
    "zabezp_ladowania", "zdarzenia_dlugotrwale", "zdr_zzr",
    "prezydencja_2025",
}

# Mapowanie podrodzaj P → dozwolone kody obiektów (pkt 8)
PODRODZAJ_P_KODY_OBIEKTOW: dict[str, list[range]] = {
    PodrodzajP.UZYTECZNOSC_PUBLICZNA.value: [range(101, 112)],
    PodrodzajP.MIESZKALNE.value: [range(201, 212)],
    PodrodzajP.PRODUKCYJNE.value: [range(301, 308)],
    PodrodzajP.MAGAZYNOWE.value: [range(401, 409)],
    PodrodzajP.TRANSPORT_DROGOWY.value: [range(501, 505)],
    PodrodzajP.TRANSPORT_KOLEJOWY.value: [range(505, 507), range(516, 519)],
    PodrodzajP.TRANSPORT_LOTNICZY.value: [range(507, 510)],
    PodrodzajP.TRANSPORT_WODNY.value: [range(510, 516)],
    PodrodzajP.TRAWY_LASY.value: [range(601, 607), range(701, 703), range(704, 705), range(817, 818)],
    PodrodzajP.GOSPODARCZE_ROLNICZE.value: [range(703, 704), range(705, 708)],
}


# ============================================================================
# CZĘŚĆ 2 - STRUKTURY DANYCH: KARTA ZDARZENIA + IzZ
# ============================================================================


@dataclass
class KartaZdarzenia:
    """Karta Zdarzenia (KZ) - dane z fazy alarmowania i dysponowania."""

    # Identyfikacja
    id_zdarzenia: str = ""
    id_si_cpr: str = ""

    # Rodzaj i klasyfikacja
    rodzaj_zdarzenia: Optional[RodzajZdarzenia] = None
    podrodzaj: str = ""  # wartość z PodrodzajP / PodrodzajMZ
    flagi: set[str] = field(default_factory=set)

    # Lokalizacja
    wojewodztwo: str = ""
    powiat: str = ""
    gmina: str = ""
    miejscowosc: str = ""
    ulica: str = ""
    numer_budynku: str = ""
    numer_drogi: str = ""
    pikietaz: str = ""
    obiekt_kz: str = ""  # obiekt w KZ (lokalizacja, nie IzZ!)
    pietro: str = ""
    wspolrzedne_geo: tuple[float, float] = (0.0, 0.0)

    # Jednostki
    jednostka_prowadzaca: str = ""
    teren_dzialania: str = ""

    # Czasy
    czas_przyjecia_zgloszenia: Optional[datetime] = None
    czas_lokalizacji: Optional[datetime] = None
    czas_zakonczenia: Optional[datetime] = None

    # Zgłoszenie
    opis_zdarzenia: str = ""
    sposob_powiadomienia: str = ""  # telefon/radio/monitoring/inny
    dane_zglaszajacego: str = ""

    # Powiadomienia
    powiadomione_sluzby: list[str] = field(default_factory=list)

    # Dysponowane SiS
    dysponowane_zastepy: list[dict] = field(default_factory=list)
    """Każdy element: {"jednostka": "JRG-1", "pojazd": "GBA 2.5/16",
                        "czas_zadysponowania": datetime, "czas_dojazdu": datetime,
                        "obsada": 4}"""

    # Karta manipulacyjna
    wpisy_manipulacyjne: list[dict] = field(default_factory=list)


@dataclass
class WypadkiZLudzmi:
    """Punkt 23 IzZ - Wypadki z ludźmi."""
    ratownicy_ranni: int = 0
    ratownicy_smiertelni: int = 0
    w_tym_strazacy_ranni: int = 0
    w_tym_strazacy_smiertelni: int = 0
    inne_osoby_ranne: int = 0
    inne_osoby_smiertelne: int = 0
    w_tym_dzieci_ranne: int = 0
    w_tym_dzieci_smiertelne: int = 0

    @property
    def total_ranni(self) -> int:
        return self.ratownicy_ranni + self.inne_osoby_ranne

    @property
    def total_smiertelni(self) -> int:
        return self.ratownicy_smiertelni + self.inne_osoby_smiertelne


@dataclass
class MDR:
    """Punkt 22 IzZ - Medyczne działania ratownicze."""
    na_terenie_akcji: int = 0
    w_tym_przez_strazakow: int = 0
    przekazano_joz: int = 0
    ewakuowano_ze_strefy: int = 0


@dataclass
class InformacjaZeZdarzenia:
    """Informacja ze Zdarzenia (IzZ) - pełna dokumentacja po zakończeniu."""

    # 1. Numer ewidencyjny
    numer_ewidencyjny: str = ""

    # 2. Współrzędne
    wspolrzedne_geo: tuple[float, float] = (0.0, 0.0)

    # 3. Ugaszono bez JOP
    ugaszono_bez_jop: bool = False

    # 4. Rodzaj zdarzenia
    rodzaj_zdarzenia: Optional[RodzajZdarzenia] = None
    wielkosc_pozaru: Optional[WielkoscPozaru] = None
    wielkosc_mz: Optional[WielkoscMZ] = None
    rodzaje_mz: list[RodzajMZ] = field(default_factory=list)
    klasyfikacja_af: Optional[KlasyfikacjaAF] = None
    dzialania_ratownicze: bool = True  # pkt 4* - czy prowadzono DR

    # Pożar lasu - dodatkowe
    pozar_lasu_typ: str = ""  # podpowierzchniowy/pokrywy gleby/całkowity/pojedyncze

    # 5. Miejsce zdarzenia
    wojewodztwo: str = ""
    powiat: str = ""
    gmina: str = ""
    miejscowosc: str = ""
    ulica: str = ""
    numer_budynku: str = ""
    numer_drogi: str = ""
    pikietaz: str = ""

    # 6. Obiekt
    obiekt: str = ""
    slowa_klucze: list[str] = field(default_factory=list)

    # 7. Właściciel
    wlasciciel: str = ""

    # 8. Kod obiektu
    kod_obiektu_glowny: int = 0
    kod_obiektu_dodatkowy: int = 0

    # 9. Kod właściciela
    kod_wlasciciela: int = 0
    kod_wlasciciela_dodatkowy: int = 0

    # 10-11. Czasy
    czas_zauwazenia: Optional[datetime] = None
    czas_zgloszenia: Optional[datetime] = None
    czas_przybycia_pierwszego: Optional[datetime] = None
    czas_lokalizacji: Optional[datetime] = None
    czas_lokalizacji_medycznej: Optional[datetime] = None
    czas_zakonczenia: Optional[datetime] = None
    czas_powrotu_ostatniego: Optional[datetime] = None
    dojazd_km: float = 0.0

    # 12. Zauważenie przez
    zauwazenie_przez: str = ""  # instalacja/pracownicy/samoloty/nadzor/osoby_postronne

    # 13. Zgłoszono
    sposob_zgloszenia: str = ""  # telefonicznie/radio/monitoring/inny

    # 14. Udział SiS
    sis_jrg: dict = field(default_factory=lambda: {"pojazdy": 0, "osoby": 0})
    sis_osp_ksrg: dict = field(default_factory=lambda: {"pojazdy": 0, "osoby": 0})
    sis_osp_inne: dict = field(default_factory=lambda: {"pojazdy": 0, "osoby": 0})
    sis_inne_jop: dict = field(default_factory=lambda: {"pojazdy": 0, "osoby": 0})
    sis_pozostale: dict = field(default_factory=dict)
    # Grupy specjalistyczne
    grupy_specjalistyczne: list[dict] = field(default_factory=list)
    grupy_operacyjne: list[dict] = field(default_factory=list)

    # 15. Sprzęt
    sprzet_uzyty: list[str] = field(default_factory=list)

    # 16. Rodzaj działań ratowniczych
    rodzaj_dzialan: list[int] = field(default_factory=list)
    """Numery z listy 1-44 (pkt 16 IzZ)."""

    # 17. Sprzęt użyty
    sprzet_ratowniczy: list[int] = field(default_factory=list)
    """Numery z listy 1-31 (pkt 17 IzZ)."""

    # 18. Miejsce prowadzenia działań
    miejsce_dzialan: list[int] = field(default_factory=list)
    """Numery 1-11 (pkt 18 IzZ)."""

    # 19. Środki gaśnicze
    prady_wody: int = 0
    prady_proszku: int = 0
    prady_piany: int = 0
    zuzyto_wody_m3: float = 0.0
    zuzyto_proszku_kg: float = 0.0
    zuzyto_srodkow_pianotworzych_dm3: float = 0.0
    zuzyto_neutralizatorow_dm3: float = 0.0
    zuzyto_sorbentow_kg: float = 0.0
    zaopatrzenie_hydranty: bool = False
    zaopatrzenie_zbiorniki_naturalne: bool = False
    zaopatrzenie_zbiorniki_sztuczne: bool = False

    # 20. ONZ
    nr_onz: list[str] = field(default_factory=list)

    # 21. Wybuchy
    wybuchy: bool = False

    # 22. MDR
    mdr: MDR = field(default_factory=MDR)

    # 23. Wypadki z ludźmi
    wypadki: WypadkiZLudzmi = field(default_factory=WypadkiZLudzmi)

    # 24. Dane poszkodowanych
    dane_poszkodowanych: list[dict] = field(default_factory=list)

    # 25. Wielkość zdarzenia
    powierzchnia_m2: float = 0.0
    powierzchnia_ha: float = 0.0

    # 26. Wielkość obiektu
    obiekt_dlugosc_m: float = 0.0
    obiekt_szerokosc_m: float = 0.0
    obiekt_wysokosc_m: float = 0.0

    # 27-28. Straty / uratowane
    straty_tys_zl: float = 0.0
    uratowane_tys_zl: float = 0.0

    # 29. Przyczyna
    przyczyna_opis: str = ""
    przyczyna_kod: int = 0

    # 30. Dane o budynku
    instalacje_ochronne: dict = field(default_factory=dict)
    rodzaj_budynku: list[str] = field(default_factory=list)
    dostep_utrudniony: bool = False
    dostep_opis: str = ""

    # 31. KDR
    kdr: list[dict] = field(default_factory=list)
    """[{"stopien": "", "nazwisko": "", "imie": "", "funkcja": "",
         "data_przejecia": datetime}]"""

    # 32. KMDR
    kmdr: str = ""

    # 33. Dane opisowe
    opis_dzialan: str = ""  # 33.1
    przybyli: list[str] = field(default_factory=list)  # 33.2
    zniszczenia: str = ""  # 33.3
    warunki_atmosferyczne: dict = field(default_factory=dict)  # 33.4
    przekazanie_miejsca: str = ""  # 33.5
    inne_uwagi: list[dict] = field(default_factory=list)  # 33.6

    # 34-35. JOP spoza terenu
    jop_spoza_gminy: int = 0
    jop_spoza_powiatu: int = 0

    # 36. Sporządził
    sporzadzil: str = ""
    wprowadzil_do_bazy: str = ""


# ============================================================================
# CZĘŚĆ 3 - SILNIK REGUŁ (RULE BASE)
# ============================================================================


class Severity(str, Enum):
    ERROR = "BŁĄD"
    WARNING = "OSTRZEŻENIE"
    INFO = "PODPOWIEDŹ"


@dataclass
class RuleResult:
    """Wynik ewaluacji reguły."""
    rule_id: str
    severity: Severity
    message: str
    field_source: str = ""  # np. "KZ.rodzaj_zdarzenia"
    field_target: str = ""  # np. "IzZ.rodzaje_mz"

    def __str__(self) -> str:
        return f"[{self.severity.value}] ({self.rule_id}) {self.message}"


class SWDRulesEngine:
    """
    Silnik reguł SWD PSP.

    Realizuje:
    - walidację wewnętrzną KZ
    - walidację wewnętrzną IzZ
    - walidację krzyżową KZ ↔ IzZ
    - generowanie podpowiedzi na podstawie częściowych danych
    """

    def __init__(self):
        self.results: list[RuleResult] = []

    def _add(self, rule_id: str, severity: Severity, msg: str,
             src: str = "", tgt: str = ""):
        self.results.append(RuleResult(rule_id, severity, msg, src, tgt))

    # ------------------------------------------------------------------
    # WALIDACJA KARTY ZDARZENIA (KZ)
    # ------------------------------------------------------------------

    def validate_kz(self, kz: KartaZdarzenia) -> list[RuleResult]:
        """Waliduje Kartę Zdarzenia."""
        self.results = []

        # KZ-001: Rodzaj zdarzenia wymagany
        if kz.rodzaj_zdarzenia is None:
            self._add("KZ-001", Severity.ERROR,
                       "Rodzaj zdarzenia jest wymagany w Karcie Zdarzenia.",
                       "KZ.rodzaj_zdarzenia")

        # KZ-002: Lokalizacja minimalna (gmina)
        if not kz.gmina:
            self._add("KZ-002", Severity.ERROR,
                       "Miejsce zdarzenia (gmina) jest wymagane do zapisania KZ.",
                       "KZ.gmina")

        # KZ-003: Jednostka prowadząca
        if not kz.jednostka_prowadzaca:
            self._add("KZ-003", Severity.ERROR,
                       "Jednostka prowadząca jest wymagana.",
                       "KZ.jednostka_prowadzaca")

        # KZ-004: Podrodzaj powinien być wybrany
        if kz.rodzaj_zdarzenia in (RodzajZdarzenia.POZAR, RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE):
            if not kz.podrodzaj:
                self._add("KZ-004", Severity.WARNING,
                           "Podrodzaj zdarzenia powinien być zawsze wybrany (Tabela 1).",
                           "KZ.podrodzaj")

        # KZ-005: Spójność podrodzaj ↔ flagi
        if kz.podrodzaj == PodrodzajMZ.POMOC_POLICJI.value and "pomoc_policji" not in kz.flagi:
            self._add("KZ-005", Severity.WARNING,
                       "Podrodzaj 'MZ - Pomoc Policji' wymaga zaznaczenia flagi 'Pomoc Policji'.",
                       "KZ.podrodzaj", "KZ.flagi")

        if kz.podrodzaj == PodrodzajMZ.POMOC_PRM.value and "pomoc_prm" not in kz.flagi:
            self._add("KZ-005b", Severity.WARNING,
                       "Podrodzaj 'MZ - Pomoc PRM' wymaga zaznaczenia flagi 'Pomoc PRM'.",
                       "KZ.podrodzaj", "KZ.flagi")

        # KZ-006: Flaga IZRM → podrodzaj nie może być Pomoc PRM (zabezpieczenie lądowania)
        if "izrm" in kz.flagi and kz.podrodzaj == PodrodzajMZ.POMOC_PRM.value:
            self._add("KZ-006", Severity.WARNING,
                       "Flaga IZRM + podrodzaj 'Pomoc PRM' - "
                       "zabezpieczenie lądowania LPR NIE jest IZRM.",
                       "KZ.flagi")

        # KZ-007: Obsada pojazdu przed dysponowaniem (PZR)
        if kz.rodzaj_zdarzenia == RodzajZdarzenia.ZABEZPIECZENIE_REJONU:
            for z in kz.dysponowane_zastepy:
                if z.get("obsada", 0) == 0:
                    self._add("KZ-007", Severity.ERROR,
                               "Przed zadysponowaniem na PZR musi być wprowadzona "
                               "prawidłowa obsada pojazdu.",
                               "KZ.dysponowane_zastepy")
                    break

        # KZ-008: Czas przyjęcia zgłoszenia
        if kz.czas_przyjecia_zgloszenia is None:
            self._add("KZ-008", Severity.ERROR,
                       "Czas przyjęcia zgłoszenia jest wymagany.",
                       "KZ.czas_przyjecia_zgloszenia")

        # KZ-009: Flagi atmosferyczne → podrodzaj Atmosferyczne
        atmo_flagi = {"przybory_wod", "silne_wiatry", "uszkodzony_dach",
                      "wiatolomy", "zerwany_dach"}
        if kz.flagi & atmo_flagi and kz.podrodzaj != PodrodzajMZ.ATMOSFERYCZNE.value:
            if kz.rodzaj_zdarzenia == RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE:
                self._add("KZ-009", Severity.WARNING,
                           f"Flagi atmosferyczne ({kz.flagi & atmo_flagi}) sugerują "
                           "podrodzaj 'MZ - Atmosferyczne'.",
                           "KZ.flagi", "KZ.podrodzaj")

        # KZ-010: Zdarzenia wymagające powiadomienia KW/KG (pkt I.2)
        self._check_powiadomienie_kw_kg(kz)

        return self.results

    def _check_powiadomienie_kw_kg(self, kz: KartaZdarzenia):
        """Sprawdza czy zdarzenie wymaga powiadomienia jednostek nadrzędnych (pkt I.2)."""
        powody = []

        if "ofiara_smiertelna" in kz.flagi:
            powody.append("ofiara śmiertelna")
        if "wypadek_ratownika" in kz.flagi:
            powody.append("wypadek ratownika")
        if "substancja_chemiczna" in kz.flagi or "substancja_radiologiczna" in kz.flagi:
            powody.append("substancja niebezpieczna CBRNe")
        if "placowka_dyplomatyczna" in kz.flagi:
            powody.append("placówka dyplomatyczna")
        if "zdr_zzr" in kz.flagi:
            powody.append("ZDR/ZZR")
        if "udzial_smiglowcow" in kz.flagi:
            powody.append("udział śmigłowców/samolotów")
        if "ewakuacja_osob" in kz.flagi:
            powody.append("ewakuacja osób")

        # Liczba zastępów ≥ 12
        n_zastepow = len(kz.dysponowane_zastepy)
        if n_zastepow >= 12:
            powody.append(f"≥12 zastępów ({n_zastepow})")

        if powody:
            if "do_wiadomosci_kw" not in kz.flagi:
                self._add("KZ-010", Severity.ERROR,
                           "Zdarzenie wymaga powiadomienia SK KW PSP "
                           f"(przesłanki: {', '.join(powody)}). "
                           "Brak flagi 'Do wiadomości KW'.",
                           "KZ.flagi")
            if "do_wiadomosci_kg" not in kz.flagi:
                self._add("KZ-010b", Severity.WARNING,
                           "Rozważ oznaczenie 'Do wiadomości KG' "
                           f"(przesłanki: {', '.join(powody)}).",
                           "KZ.flagi")

    # ------------------------------------------------------------------
    # WALIDACJA IzZ
    # ------------------------------------------------------------------

    def validate_izz(self, izz: InformacjaZeZdarzenia) -> list[RuleResult]:
        """Waliduje Informację ze Zdarzenia."""
        self.results = []

        # IZZ-001: Rodzaj zdarzenia wymagany
        if izz.rodzaj_zdarzenia is None:
            self._add("IZZ-001", Severity.ERROR,
                       "Rodzaj zdarzenia jest wymagany w IzZ.",
                       "IzZ.rodzaj_zdarzenia")
            return self.results

        # IZZ-002: Wielkość zdarzenia
        if izz.rodzaj_zdarzenia == RodzajZdarzenia.POZAR and izz.wielkosc_pozaru is None:
            self._add("IZZ-002", Severity.ERROR,
                       "Wielkość pożaru jest wymagana (P/M, P/Ś, P/D, P/BD).",
                       "IzZ.wielkosc_pozaru")

        if izz.rodzaj_zdarzenia == RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE and izz.wielkosc_mz is None:
            self._add("IZZ-002b", Severity.ERROR,
                       "Wielkość MZ jest wymagana (MZ/M .. MZ/GIK).",
                       "IzZ.wielkosc_mz")

        # IZZ-003: Rodzaj MZ
        if izz.rodzaj_zdarzenia == RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE and not izz.rodzaje_mz:
            self._add("IZZ-003", Severity.ERROR,
                       "Rodzaj MZ jest wymagany (np. chemiczne, budowlane, medyczne...).",
                       "IzZ.rodzaje_mz")

        # IZZ-004: AF - klasyfikacja
        if izz.rodzaj_zdarzenia == RodzajZdarzenia.FALSZYWY_ALARM and izz.klasyfikacja_af is None:
            self._add("IZZ-004", Severity.ERROR,
                       "Alarm fałszywy wymaga klasyfikacji (złośliwy / w dobrej wierze / "
                       "z instalacji wykrywania).",
                       "IzZ.klasyfikacja_af")

        # IZZ-005: Kod obiektu
        if izz.kod_obiektu_glowny == 0 and izz.rodzaj_zdarzenia in (
                RodzajZdarzenia.POZAR, RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE):
            self._add("IZZ-005", Severity.ERROR,
                       "Kod obiektu głównego jest wymagany.",
                       "IzZ.kod_obiektu_glowny")

        # IZZ-006: Spójność kodu obiektu z podrodzajem P
        # (mapowanie z PODRODZAJ_P_KODY_OBIEKTOW)
        # - weryfikujemy później w validate_cross

        # IZZ-007: Chronologia czasów
        self._validate_czasy(izz)

        # IZZ-008: Ofiary śmiertelne → konsekwencje
        self._validate_ofiary(izz)

        # IZZ-009: MZ/M - bez sprzętu specjalnego (poza pomiarowym)
        if (izz.wielkosc_mz == WielkoscMZ.MALE
                and izz.dzialania_ratownicze
                and izz.rodzaj_zdarzenia == RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE):
            # Sprzęt specjalny = cokolwiek poza urządzeniami pomiarowymi (12)
            sprzet_specjalny = [s for s in izz.sprzet_ratowniczy if s != 12]
            if sprzet_specjalny:
                self._add("IZZ-009", Severity.WARNING,
                           "MZ/M - użyto sprzętu specjalnego "
                           f"(pozycje: {sprzet_specjalny}). "
                           "Sprawdź, czy wielkość MZ nie powinna być wyższa.",
                           "IzZ.sprzet_ratowniczy")

        # IZZ-010: Wielkość MZ vs liczba zastępów
        self._validate_wielkosc_mz(izz)

        # IZZ-011: Tlenek węgla - UN 1016 tylko w MZ
        if "1016" in izz.nr_onz and izz.rodzaj_zdarzenia == RodzajZdarzenia.POZAR:
            self._add("IZZ-011", Severity.ERROR,
                       "W pożarach nie oznaczamy tlenku węgla (UN 1016) - "
                       "tylko w MZ.",
                       "IzZ.nr_onz")

        # IZZ-012: UN - nie zaznaczać dla płynów eksploatacyjnych
        # (kontekstowe - trudno zautomatyzować w pełni)

        # IZZ-013: MDR spójność
        self._validate_mdr(izz)

        # IZZ-014: Pożar lasu → klucz SADZE nie pasuje
        if izz.rodzaj_zdarzenia == RodzajZdarzenia.POZAR:
            if izz.kod_obiektu_glowny in range(601, 607):
                if izz.powierzchnia_ha == 0 and izz.powierzchnia_m2 == 0:
                    self._add("IZZ-014", Severity.WARNING,
                               "Pożar lasu - wymagana powierzchnia w hektarach.",
                               "IzZ.powierzchnia_ha")

        # IZZ-015: Dane opisowe - wymagane przy ofiarach
        if izz.wypadki.total_smiertelni > 0 or izz.wypadki.total_ranni > 0:
            if not izz.opis_dzialan:
                self._add("IZZ-015", Severity.ERROR,
                           "Przy poszkodowanych wymagany jest szczegółowy opis "
                           "podjętych działań wraz z zamiarem taktycznym (pkt 33.1).",
                           "IzZ.opis_dzialan")

        # IZZ-016: MZ chemiczne → UN wymagany
        if RodzajMZ.CHEMICZNE in izz.rodzaje_mz and not izz.nr_onz:
            self._add("IZZ-016", Severity.WARNING,
                       "MZ chemiczne - rozważ podanie nr ONZ substancji (pkt 20).",
                       "IzZ.nr_onz")

        # IZZ-017: IZRM → słowo-klucz IZRM w obiekcie
        if "IZRM" in izz.slowa_klucze:
            if RodzajMZ.MEDYCZNE not in izz.rodzaje_mz:
                self._add("IZZ-017", Severity.WARNING,
                           "Słowo-klucz IZRM sugeruje rodzaj MZ 'medyczne'.",
                           "IzZ.slowa_klucze", "IzZ.rodzaje_mz")

        # IZZ-018: Warunki atmosferyczne - ciśnienie wymagane przy MZ chemiczne
        if RodzajMZ.CHEMICZNE in izz.rodzaje_mz:
            if "cisnienie_hpa" not in izz.warunki_atmosferyczne:
                self._add("IZZ-018", Severity.WARNING,
                           "MZ chemiczne - wymagane ciśnienie atmosferyczne (hPa) "
                           "w warunkach atmosferycznych (pkt 33.4).",
                           "IzZ.warunki_atmosferyczne")

        return self.results

    def _validate_czasy(self, izz: InformacjaZeZdarzenia):
        """Waliduje chronologię czasów (pkt 10-11)."""
        czasy = [
            ("zauważenie", izz.czas_zauwazenia),
            ("zgłoszenie", izz.czas_zgloszenia),
            ("przybycie pierwszego", izz.czas_przybycia_pierwszego),
            ("lokalizacja", izz.czas_lokalizacji),
            ("zakończenie", izz.czas_zakonczenia),
        ]
        prev_name, prev_time = None, None
        for name, t in czasy:
            if t is None:
                continue
            if prev_time is not None and t < prev_time:
                self._add("IZZ-007", Severity.ERROR,
                           f"Czas '{name}' ({t:%H:%M}) nie może być wcześniejszy "
                           f"niż '{prev_name}' ({prev_time:%H:%M}).",
                           f"IzZ.czas_{name}")
            prev_name, prev_time = name, t

        # Lokalizacja ≠ zakończenie (chyba że przedysponowanie)
        if (izz.czas_lokalizacji and izz.czas_zakonczenia
                and izz.czas_lokalizacji == izz.czas_zakonczenia):
            self._add("IZZ-007b", Severity.WARNING,
                       "Godzina lokalizacji i zakończenia nie powinny być takie same "
                       "(wyjątek: przedysponowanie do innego zdarzenia).",
                       "IzZ.czas_lokalizacji")

        # Lokalizacja medyczna ≤ lokalizacja zdarzenia
        if (izz.czas_lokalizacji_medycznej and izz.czas_lokalizacji
                and izz.czas_lokalizacji_medycznej > izz.czas_lokalizacji):
            self._add("IZZ-007c", Severity.ERROR,
                       "Czas lokalizacji medycznej nie może być późniejszy "
                       "niż czas lokalizacji zdarzenia.",
                       "IzZ.czas_lokalizacji_medycznej")

    def _validate_ofiary(self, izz: InformacjaZeZdarzenia):
        """Walidacja reguł dotyczących ofiar (pkt 23-24)."""
        if izz.wypadki.total_smiertelni > 0:
            # Dane poszkodowanych wymagane
            if not izz.dane_poszkodowanych:
                self._add("IZZ-008a", Severity.ERROR,
                           "Ofiary śmiertelne wymagają uzupełnienia danych "
                           "personalnych poszkodowanych (pkt 24).",
                           "IzZ.dane_poszkodowanych")

        if izz.wypadki.w_tym_strazacy_ranni > izz.wypadki.ratownicy_ranni:
            self._add("IZZ-008b", Severity.ERROR,
                       "'W tym strażacy ranni' nie może przekraczać 'Ratownicy ranni'.",
                       "IzZ.wypadki")

        if izz.wypadki.w_tym_dzieci_ranne > izz.wypadki.inne_osoby_ranne:
            self._add("IZZ-008c", Severity.ERROR,
                       "'W tym dzieci ranne' nie może przekraczać 'Inne osoby ranne'.",
                       "IzZ.wypadki")

    def _validate_wielkosc_mz(self, izz: InformacjaZeZdarzenia):
        """Sprawdza spójność wielkości MZ z parametrami (pkt 4.2)."""
        if izz.rodzaj_zdarzenia != RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE:
            return
        if izz.wielkosc_mz is None:
            return

        total_zastepow = (izz.sis_jrg.get("pojazdy", 0)
                          + izz.sis_osp_ksrg.get("pojazdy", 0)
                          + izz.sis_osp_inne.get("pojazdy", 0)
                          + izz.sis_inne_jop.get("pojazdy", 0))
        smiertelni = izz.wypadki.total_smiertelni
        ranni_zabrani = izz.wypadki.total_ranni

        # MZ/L: ≤1 śmiertelny LUB ≤3 osoby zabrane LUB ≤4 zastępy
        if izz.wielkosc_mz == WielkoscMZ.LOKALNE:
            if smiertelni > 1:
                self._add("IZZ-010a", Severity.WARNING,
                           f"MZ/L: >1 ofiara śmiertelna ({smiertelni}) "
                           "- rozważ MZ/Ś lub wyższe.",
                           "IzZ.wielkosc_mz")
            if ranni_zabrani > 3:
                self._add("IZZ-010b", Severity.WARNING,
                           f"MZ/L: >3 osoby zabrane przez ZRM ({ranni_zabrani}) "
                           "- rozważ MZ/Ś.",
                           "IzZ.wielkosc_mz")
            if total_zastepow > 4:
                self._add("IZZ-010c", Severity.WARNING,
                           f"MZ/L: >4 zastępy ({total_zastepow}) - rozważ MZ/Ś.",
                           "IzZ.wielkosc_mz")

        # MZ/Ś: 2-3 śmiertelnych LUB 4-10 rannych LUB 5-12 zastępów
        if izz.wielkosc_mz == WielkoscMZ.SREDNIE:
            if smiertelni > 3:
                self._add("IZZ-010d", Severity.WARNING,
                           f"MZ/Ś: >3 ofiary śmiertelne ({smiertelni}) "
                           "- rozważ MZ/D.",
                           "IzZ.wielkosc_mz")
            if total_zastepow > 12:
                self._add("IZZ-010e", Severity.WARNING,
                           f"MZ/Ś: >12 zastępów ({total_zastepow}) - rozważ MZ/D.",
                           "IzZ.wielkosc_mz")

    def _validate_mdr(self, izz: InformacjaZeZdarzenia):
        """Walidacja MDR (pkt 22)."""
        mdr = izz.mdr
        if mdr.w_tym_przez_strazakow > mdr.na_terenie_akcji:
            self._add("IZZ-013a", Severity.ERROR,
                       "MDR 'w tym przez strażaków' nie może przekraczać "
                       "'na terenie akcji'.",
                       "IzZ.mdr")
        if mdr.przekazano_joz > mdr.w_tym_przez_strazakow:
            self._add("IZZ-013b", Severity.WARNING,
                       "MDR 'przekazano JOZ' przekracza 'w tym przez strażaków' - "
                       "sprawdź dane.",
                       "IzZ.mdr")
        if mdr.ewakuowano_ze_strefy > mdr.na_terenie_akcji:
            self._add("IZZ-013c", Severity.ERROR,
                       "MDR 'ewakuowano ze strefy' nie może przekraczać "
                       "'na terenie akcji'.",
                       "IzZ.mdr")

        # Jeśli MDR > 0, powinny być zaznaczone odpowiednie działania (pkt 16: 34-42)
        mdr_dzialania = set(range(34, 43))
        if mdr.w_tym_przez_strazakow > 0:
            if not (set(izz.rodzaj_dzialan) & mdr_dzialania):
                self._add("IZZ-013d", Severity.WARNING,
                           "MDR przez strażaków > 0, ale brak odpowiednich "
                           "działań ratowniczych z zakresu MDR (pkt 16, poz. 34-42).",
                           "IzZ.rodzaj_dzialan")

            # Sprzęt medyczny (pkt 17: 26-31)
            sprzet_mdr = set(range(26, 32))
            if not (set(izz.sprzet_ratowniczy) & sprzet_mdr):
                self._add("IZZ-013e", Severity.WARNING,
                           "MDR przez strażaków > 0, ale brak sprzętu medycznego "
                           "(pkt 17, poz. 26-31).",
                           "IzZ.sprzet_ratowniczy")

    # ------------------------------------------------------------------
    # WALIDACJA KRZYŻOWA KZ ↔ IzZ
    # ------------------------------------------------------------------

    def validate_cross(self, kz: KartaZdarzenia, izz: InformacjaZeZdarzenia
                       ) -> list[RuleResult]:
        """Walidacja krzyżowa - spójność Karty Zdarzenia z IzZ."""
        self.results = []

        # CROSS-001: Zgodność rodzaju zdarzenia
        if (kz.rodzaj_zdarzenia is not None
                and izz.rodzaj_zdarzenia is not None
                and kz.rodzaj_zdarzenia != izz.rodzaj_zdarzenia):
            self._add("CROSS-001", Severity.ERROR,
                       f"Rodzaj zdarzenia w KZ ({kz.rodzaj_zdarzenia.value}) "
                       f"≠ IzZ ({izz.rodzaj_zdarzenia.value}).",
                       "KZ.rodzaj_zdarzenia", "IzZ.rodzaj_zdarzenia")

        # CROSS-002: Czas przybycia ≥ czas zadysponowania
        if kz.dysponowane_zastepy and izz.czas_przybycia_pierwszego:
            najwczesniejsze_dysp = min(
                (z["czas_zadysponowania"] for z in kz.dysponowane_zastepy
                 if z.get("czas_zadysponowania")),
                default=None
            )
            if (najwczesniejsze_dysp
                    and izz.czas_przybycia_pierwszego < najwczesniejsze_dysp):
                self._add("CROSS-002", Severity.ERROR,
                           "Czas przybycia pierwszego zastępu w IzZ "
                           f"({izz.czas_przybycia_pierwszego:%H:%M}) jest wcześniejszy "
                           f"niż czas zadysponowania w KZ ({najwczesniejsze_dysp:%H:%M}).",
                           "IzZ.czas_przybycia_pierwszego",
                           "KZ.dysponowane_zastepy")

        # CROSS-003: Czas zgłoszenia IzZ = czas przyjęcia KZ
        if (kz.czas_przyjecia_zgloszenia and izz.czas_zgloszenia
                and kz.czas_przyjecia_zgloszenia != izz.czas_zgloszenia):
            self._add("CROSS-003", Severity.WARNING,
                       "Czas zgłoszenia w IzZ powinien odpowiadać "
                       "czasowi przyjęcia zgłoszenia w KZ.",
                       "KZ.czas_przyjecia_zgloszenia", "IzZ.czas_zgloszenia")

        # CROSS-004: Liczba SiS - zastępy z KZ vs pojazdy w IzZ
        n_zastepow_kz = len(kz.dysponowane_zastepy)
        n_pojazdow_izz = (izz.sis_jrg.get("pojazdy", 0)
                          + izz.sis_osp_ksrg.get("pojazdy", 0)
                          + izz.sis_osp_inne.get("pojazdy", 0)
                          + izz.sis_inne_jop.get("pojazdy", 0))
        if n_zastepow_kz > 0 and n_pojazdow_izz > 0:
            if n_pojazdow_izz != n_zastepow_kz:
                self._add("CROSS-004", Severity.WARNING,
                           f"Liczba pojazdów w IzZ ({n_pojazdow_izz}) ≠ "
                           f"zastępów w KZ ({n_zastepow_kz}). "
                           "Sprawdź, czy nie było podmian lub zawróceń.",
                           "KZ.dysponowane_zastepy", "IzZ.sis_*")

        # CROSS-005: Flaga ofiara śmiertelna ↔ wypadki w IzZ
        if "ofiara_smiertelna" in kz.flagi and izz.wypadki.total_smiertelni == 0:
            self._add("CROSS-005a", Severity.ERROR,
                       "Flaga 'Ofiara śmiertelna' w KZ, ale brak ofiar "
                       "śmiertelnych w pkt 23 IzZ.",
                       "KZ.flagi", "IzZ.wypadki")
        if izz.wypadki.total_smiertelni > 0 and "ofiara_smiertelna" not in kz.flagi:
            self._add("CROSS-005b", Severity.ERROR,
                       "Ofiary śmiertelne w IzZ (pkt 23), ale brak flagi "
                       "'Ofiara śmiertelna' w KZ.",
                       "IzZ.wypadki", "KZ.flagi")

        # CROSS-006: Flaga osoba ranna ↔ wypadki w IzZ
        if "osoba_ranna" in kz.flagi and izz.wypadki.total_ranni == 0:
            self._add("CROSS-006a", Severity.WARNING,
                       "Flaga 'Osoba ranna' w KZ, ale 0 rannych w pkt 23 IzZ.",
                       "KZ.flagi", "IzZ.wypadki")
        if izz.wypadki.total_ranni > 0 and "osoba_ranna" not in kz.flagi:
            self._add("CROSS-006b", Severity.WARNING,
                       "Osoby ranne w IzZ (pkt 23), ale brak flagi "
                       "'Osoba ranna' w KZ.",
                       "IzZ.wypadki", "KZ.flagi")

        # CROSS-007: Flaga wypadek ratownika ↔ ratownicy ranni/śmiertelni
        if "wypadek_ratownika" in kz.flagi:
            if (izz.wypadki.ratownicy_ranni == 0
                    and izz.wypadki.ratownicy_smiertelni == 0):
                self._add("CROSS-007", Severity.ERROR,
                           "Flaga 'Wypadek ratownika' w KZ, ale brak "
                           "rannych/śmiertelnych ratowników w pkt 23 IzZ.",
                           "KZ.flagi", "IzZ.wypadki")

        # CROSS-008: Ofiary śmiertelne → powiadomienie Prokuratora/Policji
        if izz.wypadki.total_smiertelni > 0:
            policja_powiadomiona = "Policja" in kz.powiadomione_sluzby or any(
                "Policja" in str(s) or "Prokurat" in str(s)
                for s in kz.powiadomione_sluzby
            )
            if not policja_powiadomiona:
                self._add("CROSS-008", Severity.WARNING,
                           "Ofiary śmiertelne - sprawdź powiadomienie "
                           "Prokuratora / Policji w KZ.",
                           "IzZ.wypadki", "KZ.powiadomione_sluzby")

        # CROSS-009: Flaga tlenek_wegla + MZ chemiczne
        if "tlenek_wegla" in kz.flagi:
            if izz.rodzaj_zdarzenia == RodzajZdarzenia.POZAR:
                self._add("CROSS-009", Severity.ERROR,
                           "Flaga 'Tlenek węgla' w KZ, ale rodzaj zdarzenia "
                           "to P (pożar). Tlenek węgla ewidencjonuje się "
                           "tylko w MZ.",
                           "KZ.flagi", "IzZ.rodzaj_zdarzenia")

        # CROSS-010: Substancja chemiczna → MZ chemiczne w IzZ
        if "substancja_chemiczna" in kz.flagi:
            if (izz.rodzaj_zdarzenia == RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE
                    and RodzajMZ.CHEMICZNE not in izz.rodzaje_mz):
                self._add("CROSS-010", Severity.WARNING,
                           "Flaga 'Substancja chemiczna' w KZ - rozważ "
                           "dodanie rodzaju MZ 'chemiczne' w IzZ.",
                           "KZ.flagi", "IzZ.rodzaje_mz")

        # CROSS-011: Flaga IZRM → słowo-klucz IZRM w obiekcie IzZ
        if "izrm" in kz.flagi:
            if "IZRM" not in izz.slowa_klucze:
                self._add("CROSS-011", Severity.WARNING,
                           "Flaga IZRM w KZ - wymagane słowo-klucz 'IZRM' "
                           "w polu Obiekt IzZ.",
                           "KZ.flagi", "IzZ.slowa_klucze")
            if RodzajMZ.MEDYCZNE not in izz.rodzaje_mz:
                self._add("CROSS-011b", Severity.WARNING,
                           "Flaga IZRM w KZ - rodzaj MZ 'medyczne' "
                           "powinien być zaznaczony w IzZ.",
                           "KZ.flagi", "IzZ.rodzaje_mz")

        # CROSS-012: Zabezpieczenie lądowania → flaga + LPR w pkt 14/15
        if "zabezp_ladowania" in kz.flagi:
            has_prm = izz.sis_pozostale.get("pogotowie_ratunkowe", {}).get("pojazdy", 0) > 0
            if not has_prm:
                self._add("CROSS-012", Severity.WARNING,
                           "Flaga 'Zabezpieczenie lądowania' - sprawdź, "
                           "czy pogotowie ratunkowe jest ujęte w pkt 14 IzZ.",
                           "KZ.flagi", "IzZ.sis_pozostale")

        # CROSS-013: Lokalizacja KZ → IzZ
        if kz.gmina and izz.gmina and kz.gmina != izz.gmina:
            self._add("CROSS-013", Severity.WARNING,
                       f"Gmina w KZ ({kz.gmina}) ≠ IzZ ({izz.gmina}). "
                       "Sprawdź aktualizację adresu.",
                       "KZ.gmina", "IzZ.gmina")

        # CROSS-014: Podrodzaj P → kod obiektu
        if kz.podrodzaj in PODRODZAJ_P_KODY_OBIEKTOW and izz.kod_obiektu_glowny > 0:
            dozwolone = PODRODZAJ_P_KODY_OBIEKTOW[kz.podrodzaj]
            if not any(izz.kod_obiektu_glowny in r for r in dozwolone):
                self._add("CROSS-014", Severity.WARNING,
                           f"Podrodzaj '{kz.podrodzaj}' sugeruje kody obiektów "
                           f"{[list(r) for r in dozwolone]}, "
                           f"a w IzZ wpisano {izz.kod_obiektu_glowny}.",
                           "KZ.podrodzaj", "IzZ.kod_obiektu_glowny")

        # CROSS-015: Wielkość pożaru vs powierzchnia
        if izz.wielkosc_pozaru and izz.powierzchnia_m2 > 0:
            p = izz.powierzchnia_m2
            expected = None
            if p <= 70:
                expected = WielkoscPozaru.MALY
            elif p <= 300:
                expected = WielkoscPozaru.SREDNI
            elif p <= 1000:
                expected = WielkoscPozaru.DUZY
            else:
                expected = WielkoscPozaru.BARDZO_DUZY
            if expected and izz.wielkosc_pozaru != expected:
                self._add("CROSS-015", Severity.WARNING,
                           f"Powierzchnia {p} m² sugeruje wielkość pożaru "
                           f"'{expected.value}', a wpisano '{izz.wielkosc_pozaru.value}'.",
                           "IzZ.powierzchnia_m2", "IzZ.wielkosc_pozaru")

        return self.results

    # ------------------------------------------------------------------
    # SYSTEM PODPOWIEDZI
    # ------------------------------------------------------------------

    def get_suggestions(self, kz: Optional[KartaZdarzenia] = None,
                        izz: Optional[InformacjaZeZdarzenia] = None
                        ) -> list[RuleResult]:
        """
        Generuje podpowiedzi na podstawie częściowo wypełnionych danych.

        Zwraca sugestie typu INFO (co warto uzupełnić) oraz
        OSTRZEŻENIA (co wydaje się niespójne).
        """
        self.results = []

        if kz:
            self._suggest_from_kz(kz)
        if izz:
            self._suggest_from_izz(izz)
        if kz and izz:
            self._suggest_cross(kz, izz)

        return self.results

    def _suggest_from_kz(self, kz: KartaZdarzenia):
        """Podpowiedzi na podstawie danych KZ."""

        # SUG-001: Pożar → sugerowane flagi
        if kz.rodzaj_zdarzenia == RodzajZdarzenia.POZAR:
            if kz.podrodzaj == PodrodzajP.TRAWY_LASY.value:
                if "pozar_lasu" not in kz.flagi and "pozar_trawy" not in kz.flagi:
                    self._add("SUG-001", Severity.INFO,
                               "Pożar trawy/lasu - rozważ zaznaczenie flagi "
                               "'Pożar lasu', 'Pożar pól' lub 'Pożar trawy'.",
                               "KZ.podrodzaj")

            if kz.podrodzaj == PodrodzajP.MIESZKALNE.value:
                if "obiekt_mieszkalny" not in kz.flagi:
                    self._add("SUG-001b", Severity.INFO,
                               "Pożar obiektu mieszkalnego - rozważ flagę "
                               "'Obiekt mieszkalny'.",
                               "KZ.podrodzaj")

        # SUG-002: MZ chemiczne → sugestie
        if kz.rodzaj_zdarzenia == RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE:
            if kz.podrodzaj == PodrodzajMZ.CHEMICZNE.value:
                self._add("SUG-002", Severity.INFO,
                           "MZ Chemiczne - sprawdź:\n"
                           "  • Zadysponuj SGRChem jeśli wymagane\n"
                           "  • Oznacz flagę 'Substancja chemiczna'\n"
                           "  • W IzZ podaj nr ONZ substancji (pkt 20)\n"
                           "  • W IzZ wpisz ciśnienie atm. (hPa) w pkt 33.4\n"
                           "  • Rozważ flagę GIOŚ/WIOŚ przy poważnej awarii",
                           "KZ.podrodzaj")

            if kz.podrodzaj == PodrodzajMZ.WYPADEK.value:
                self._add("SUG-002b", Severity.INFO,
                           "MZ Wypadek drogowy - w IzZ:\n"
                           "  • Oznacz rodzaj MZ 'w transporcie drogowym'\n"
                           "  • Wpisz markę, model, nr rej. w polu Obiekt\n"
                           "  • Podaj nr i pikietaż drogi\n"
                           "  • Uzupełnij pkt 22 (MDR) i 23 (wypadki z ludźmi)\n"
                           "  • Zaznacz 'Osoba ranna' / 'Ofiara śmiertelna' w flagach",
                           "KZ.podrodzaj")

            if kz.podrodzaj == PodrodzajMZ.POMOC_OTWARCIE.value:
                self._add("SUG-002c", Severity.INFO,
                           "Pomoc w otwarciu mieszkania - pamiętaj:\n"
                           "  • Jeśli na prośbę Policji: zaznacz flagę 'Pomoc Policji'\n"
                           "  • Kwalifikuj jako MZ (nie AF) nawet gdy nikogo nie zastano",
                           "KZ.podrodzaj")

            if kz.podrodzaj == PodrodzajMZ.ZWIERZETA.value:
                self._add("SUG-002d", Severity.INFO,
                           "MZ Zwierzęta - w IzZ:\n"
                           "  • Użyj słowa-klucza 'OWADY' jeśli usuwanie owadów\n"
                           "  • Zaznacz flagę 'Usuwanie owadów' jeśli dotyczy\n"
                           "  • Działanie pkt 16: poz. 43 (przemieszczanie owadów/zwierząt)",
                           "KZ.podrodzaj")

        # SUG-003: Dużo zastępów → rozważ flagę "Do wiadomości KW"
        n = len(kz.dysponowane_zastepy)
        if n >= 8 and "do_wiadomosci_kw" not in kz.flagi:
            self._add("SUG-003", Severity.INFO,
                       f"Zadysponowano {n} zastępów - zbliżasz się do progu "
                       "12 zastępów wymagającego powiadomienia SK KW PSP.",
                       "KZ.dysponowane_zastepy")

        # SUG-004: Śmigłowiec
        if "udzial_smiglowcow" in kz.flagi:
            if "lpr_169" not in kz.flagi and "lpr_kswl" not in kz.flagi:
                self._add("SUG-004", Severity.INFO,
                           "Udział śmigłowca - rozważ flagę 'LPR_169.000' "
                           "lub 'LPR_KSWL' (kanał łączności).",
                           "KZ.flagi")

    def _suggest_from_izz(self, izz: InformacjaZeZdarzenia):
        """Podpowiedzi z częściowo wypełnionej IzZ."""

        # SUG-010: Pożar lasu → parametry
        if izz.rodzaj_zdarzenia == RodzajZdarzenia.POZAR:
            if izz.kod_obiektu_glowny in range(601, 607):
                self._add("SUG-010", Severity.INFO,
                           "Pożar lasu - wymagane:\n"
                           "  • Powierzchnia uprawy/drzewostanu w ha\n"
                           "  • Typ pożaru: podpowierzchniowy / pokrywy gleby / "
                           "całkowity drzew / pojedyncze drzewo\n"
                           "  • Klasa wieku drzewostanu (kod 601-606)",
                           "IzZ.kod_obiektu_glowny")

        # SUG-011: Pojazd elektryczny → słowo-klucz
        if izz.obiekt and any(kw in izz.obiekt.upper()
                              for kw in ["ELEKTR", "TESLA", "BEV", "EV"]):
            if "POJAZDY ELEKTRYCZNE" not in izz.slowa_klucze:
                self._add("SUG-011", Severity.INFO,
                           "Wykryto potencjalny pojazd elektryczny - "
                           "dodaj słowo-klucz 'POJAZDY ELEKTRYCZNE'.",
                           "IzZ.obiekt")

        # SUG-012: Pożar z ofiarami → pkt 33.1 rozbudowany opis
        if (izz.rodzaj_zdarzenia == RodzajZdarzenia.POZAR
                and izz.wypadki.total_smiertelni > 0):
            self._add("SUG-012", Severity.INFO,
                       "Pożar z ofiarami śmiertelnymi - IzZ powinna być "
                       "traktowana priorytetowo i zatwierdzona w jak "
                       "najkrótszym czasie.",
                       "IzZ.wypadki")

        # SUG-013: Wypadek → pożar jako następstwo MZ
        if (izz.rodzaj_zdarzenia == RodzajZdarzenia.POZAR
                and izz.przyczyna_kod == 35):
            self._add("SUG-013", Severity.INFO,
                       "Przyczyna 35 (pożar jako następstwo MZ) - "
                       "w danych opisowych (pkt 33.1) opisz przyczynę "
                       "zdarzenia przed powstaniem pożaru. "
                       "Poszkodowanych wykaż w pkt 23 jak w innych pożarach.",
                       "IzZ.przyczyna_kod")

        # SUG-014: MZ medyczne + brak KDR koordynacji MDR
        if (RodzajMZ.MEDYCZNE in izz.rodzaje_mz
                and izz.mdr.na_terenie_akcji > 0
                and not izz.kmdr):
            self._add("SUG-014", Severity.INFO,
                       "MZ medyczne z MDR - uzupełnij pkt 32 "
                       "(Koordynacja medycznych działań ratowniczych).",
                       "IzZ.kmdr")

    def _suggest_cross(self, kz: KartaZdarzenia, izz: InformacjaZeZdarzenia):
        """Podpowiedzi krzyżowe KZ ↔ IzZ."""

        # SUG-020: MZ chemiczne w KZ ale P w IzZ
        if (kz.podrodzaj == PodrodzajMZ.CHEMICZNE.value
                and izz.rodzaj_zdarzenia == RodzajZdarzenia.POZAR):
            self._add("SUG-020", Severity.WARNING,
                       "W KZ wskazano MZ Chemiczne, a w IzZ rodzaj to Pożar. "
                       "Sprawdź kwalifikację zdarzenia.",
                       "KZ.podrodzaj", "IzZ.rodzaj_zdarzenia")

        # SUG-021: Ewakuacja w KZ ale brak w IzZ pkt 16
        if "ewakuacja_osob" in kz.flagi:
            if 6 not in izz.rodzaj_dzialan:  # 6 = Ewakuacja ludzi
                self._add("SUG-021", Severity.INFO,
                           "Flaga 'Ewakuacja osób' w KZ - sprawdź, czy "
                           "pkt 16 IzZ zawiera 'Ewakuacja ludzi' (poz. 6). "
                           "Podaj liczbę ewakuowanych w pkt 33.1.",
                           "KZ.flagi", "IzZ.rodzaj_dzialan")

    # ------------------------------------------------------------------
    # PEŁNA WALIDACJA (ALL-IN-ONE)
    # ------------------------------------------------------------------

    def validate_all(self, kz: KartaZdarzenia, izz: InformacjaZeZdarzenia
                     ) -> list[RuleResult]:
        """Uruchamia wszystkie walidacje i zwraca zbiorczy wynik."""
        all_results = []
        all_results.extend(self.validate_kz(kz))
        all_results.extend(self.validate_izz(izz))
        all_results.extend(self.validate_cross(kz, izz))
        all_results.extend(self.get_suggestions(kz, izz))
        return all_results


# ============================================================================
# CZĘŚĆ 4 - FORMATOWANIE WYNIKÓW
# ============================================================================


def print_results(results: list[RuleResult], title: str = ""):
    """Czytelne wydrukowanie wyników walidacji."""
    if title:
        print(f"\n{'='*70}")
        print(f"  {title}")
        print(f"{'='*70}")

    errors = [r for r in results if r.severity == Severity.ERROR]
    warnings = [r for r in results if r.severity == Severity.WARNING]
    infos = [r for r in results if r.severity == Severity.INFO]

    if not results:
        print("  ✓ Brak uwag - dane wyglądają poprawnie.")
        return

    if errors:
        print(f"\n  ✗ BŁĘDY ({len(errors)}):")
        for r in errors:
            print(f"    • {r}")
    if warnings:
        print(f"\n  ⚠ OSTRZEŻENIA ({len(warnings)}):")
        for r in warnings:
            print(f"    • {r}")
    if infos:
        print(f"\n  ℹ PODPOWIEDZI ({len(infos)}):")
        for r in infos:
            print(f"    • {r}")

    print(f"\n  Podsumowanie: {len(errors)} błędów, "
          f"{len(warnings)} ostrzeżeń, {len(infos)} podpowiedzi")


# ============================================================================
# CZĘŚĆ 5 - TESTY: GREEN PATH + RED PATH
# ============================================================================


def test_green_path():
    """
    SCENARIUSZ POPRAWNY (GREEN PATH)
    --------------------------------
    Pożar mieszkania w budynku wielorodzinnym.
    1 zastęp JRG + 1 zastęp OSP ksrg.
    1 osoba poszkodowana (oparzenia), ZRM zabrał do szpitala.
    Pożar mały (40 m²), ugaszony 1 prądem wody.
    """
    print("\n" + "#" * 70)
    print("  TEST: GREEN PATH - Pożar mieszkania (poprawne dane)")
    print("#" * 70)

    kz = KartaZdarzenia(
        id_zdarzenia="Z-2025-001234",
        rodzaj_zdarzenia=RodzajZdarzenia.POZAR,
        podrodzaj=PodrodzajP.MIESZKALNE.value,
        flagi={"obiekt_mieszkalny", "osoba_ranna"},
        wojewodztwo="mazowieckie",
        powiat="Warszawa",
        gmina="Mokotów",
        miejscowosc="Warszawa",
        ulica="Puławska",
        numer_budynku="152/38",
        jednostka_prowadzaca="JRG-4 Warszawa",
        teren_dzialania="JRG-4 Warszawa",
        czas_przyjecia_zgloszenia=datetime(2025, 3, 15, 14, 30),
        opis_zdarzenia="1. Dym z okna mieszkania na 3 piętrze. "
                       "2. Po dojeździe: pożar pokoju, 1 osoba z oparzeniami.",
        sposob_powiadomienia="telefon",
        dane_zglaszajacego="Jan Nowak, tel. 601-123-456",
        powiadomione_sluzby=["Policja", "PRM"],
        dysponowane_zastepy=[
            {"jednostka": "JRG-4", "pojazd": "GBA 2.5/16",
             "czas_zadysponowania": datetime(2025, 3, 15, 14, 31),
             "czas_dojazdu": datetime(2025, 3, 15, 14, 38), "obsada": 6},
            {"jednostka": "OSP Ursus ksrg", "pojazd": "GBA 2.5/16",
             "czas_zadysponowania": datetime(2025, 3, 15, 14, 32),
             "czas_dojazdu": datetime(2025, 3, 15, 14, 45), "obsada": 4},
        ],
    )

    izz = InformacjaZeZdarzenia(
        numer_ewidencyjny="14640010001",
        wspolrzedne_geo=(52.1935, 21.0355),
        rodzaj_zdarzenia=RodzajZdarzenia.POZAR,
        wielkosc_pozaru=WielkoscPozaru.MALY,
        dzialania_ratownicze=True,
        wojewodztwo="mazowieckie",
        powiat="Warszawa",
        gmina="Mokotów",
        miejscowosc="Warszawa",
        ulica="Puławska",
        numer_budynku="152/38",
        obiekt="Budynek mieszkalny wielorodzinny, mieszkanie nr 38",
        kod_obiektu_glowny=209,
        kod_wlasciciela=610,
        czas_zauwazenia=datetime(2025, 3, 15, 14, 25),
        czas_zgloszenia=datetime(2025, 3, 15, 14, 30),
        czas_przybycia_pierwszego=datetime(2025, 3, 15, 14, 38),
        czas_lokalizacji=datetime(2025, 3, 15, 14, 55),
        czas_zakonczenia=datetime(2025, 3, 15, 15, 30),
        czas_powrotu_ostatniego=datetime(2025, 3, 15, 16, 0),
        dojazd_km=3.2,
        zauwazenie_przez="pracownicy_lub_mieszkancy",
        sposob_zgloszenia="telefonicznie",
        sis_jrg={"pojazdy": 1, "osoby": 6},
        sis_osp_ksrg={"pojazdy": 1, "osoby": 4},
        rodzaj_dzialan=[1, 4, 6, 9, 10, 19, 36, 39, 40, 42],
        sprzet_ratowniczy=[1, 4, 13, 22, 23, 26, 28, 29],
        miejsce_dzialan=[3],  # piętra 1-3
        prady_wody=1,
        zuzyto_wody_m3=2.5,
        zaopatrzenie_hydranty=True,
        mdr=MDR(na_terenie_akcji=1, w_tym_przez_strazakow=1,
                przekazano_joz=1, ewakuowano_ze_strefy=1),
        wypadki=WypadkiZLudzmi(inne_osoby_ranne=1),
        dane_poszkodowanych=[
            {"oznaczenie": "P1", "wiek": 45, "plec": "M", "rodzaj": "R"}
        ],
        powierzchnia_m2=40.0,
        obiekt_dlugosc_m=48.0,
        obiekt_szerokosc_m=14.0,
        obiekt_wysokosc_m=16.0,
        straty_tys_zl=35.0,
        uratowane_tys_zl=450.0,
        przyczyna_opis="Nieostrożność przy gotowaniu - zapalenie oleju na patelni",
        przyczyna_kod=5,  # NOD w pozostałych przypadkach
        rodzaj_budynku=["kompleks budynków", "średniowysoki"],
        kdr=[{"stopien": "kpt.", "nazwisko": "Kowalski",
              "imie": "Adam", "funkcja": "d-ca zmiany JRG-4",
              "data_przejecia": datetime(2025, 3, 15, 14, 38)}],
        opis_dzialan="Po dojeździe na miejsce zastano dym wydobywający się z okna "
                     "mieszkania na III piętrze. KDR zarządził rozpoznanie z 1 prądem "
                     "wody. W mieszkaniu odnaleziono 1 osobę z oparzeniami rąk. "
                     "Ewakuowano poszkodowanego ze strefy zagrożenia. Udzielono KPP. "
                     "ZRM przejął poszkodowanego o godz. 14:50.",
        warunki_atmosferyczne={
            "temperatura": "8°C",
            "wiatr": "NW, 3 m/s",
            "opady": "brak",
        },
        przekazanie_miejsca="Przekazano Policji o godz. 15:25, "
                            "z zaleceniem zabezpieczenia mieszkania.",
        sporzadzil="kpt. Adam Kowalski, Warszawa, 15.03.2025 (16:00)",
    )

    engine = SWDRulesEngine()

    results_kz = engine.validate_kz(kz)
    print_results(results_kz, "Walidacja Karty Zdarzenia (KZ)")

    results_izz = engine.validate_izz(izz)
    print_results(results_izz, "Walidacja Informacji ze Zdarzenia (IzZ)")

    results_cross = engine.validate_cross(kz, izz)
    print_results(results_cross, "Walidacja krzyżowa KZ ↔ IzZ")

    results_sug = engine.get_suggestions(kz, izz)
    print_results(results_sug, "Podpowiedzi")


def test_red_path():
    """
    SCENARIUSZ BŁĘDNY (RED PATH)
    ----------------------------
    Zdarzenie pełne błędów logicznych:
    - KZ mówi o MZ, a IzZ o Pożarze
    - Czas przybycia < czas zadysponowania
    - Ofiary śmiertelne bez flagi i bez danych personalnych
    - Tlenek węgla przy pożarze
    - MDR niespójne
    - Wielkość MZ nie pasuje do liczby zastępów
    """
    print("\n" + "#" * 70)
    print("  TEST: RED PATH - Zdarzenie z licznymi błędami")
    print("#" * 70)

    kz = KartaZdarzenia(
        id_zdarzenia="Z-2025-009999",
        rodzaj_zdarzenia=RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE,
        podrodzaj=PodrodzajMZ.CHEMICZNE.value,
        flagi={"tlenek_wegla", "pomoc_prm"},
        # Brak gminy - błąd
        wojewodztwo="śląskie",
        powiat="Katowice",
        gmina="",  # BŁĄD: brak gminy
        jednostka_prowadzaca="",  # BŁĄD: brak jednostki
        czas_przyjecia_zgloszenia=datetime(2025, 4, 10, 8, 0),
        dysponowane_zastepy=[
            {"jednostka": "JRG-1 Katowice", "pojazd": "GBA 2.5/16",
             "czas_zadysponowania": datetime(2025, 4, 10, 8, 5),
             "obsada": 5},
            {"jednostka": "JRG-2 Katowice", "pojazd": "GCBA 5/32",
             "czas_zadysponowania": datetime(2025, 4, 10, 8, 5),
             "obsada": 4},
            {"jednostka": "JRG-3 Katowice", "pojazd": "SLOp",
             "czas_zadysponowania": datetime(2025, 4, 10, 8, 10),
             "obsada": 2},
            {"jednostka": "JRG-4 Katowice", "pojazd": "GBA",
             "czas_zadysponowania": datetime(2025, 4, 10, 8, 10),
             "obsada": 5},
            {"jednostka": "JRG-5 Katowice", "pojazd": "GBA",
             "czas_zadysponowania": datetime(2025, 4, 10, 8, 12),
             "obsada": 5},
            {"jednostka": "OSP-A", "pojazd": "GBA",
             "czas_zadysponowania": datetime(2025, 4, 10, 8, 15),
             "obsada": 4},
        ],
        powiadomione_sluzby=[],  # BŁĄD: brak powiadomień mimo ofiar
    )

    izz = InformacjaZeZdarzenia(
        # BŁĄD: KZ mówi MZ, a IzZ mówi P
        rodzaj_zdarzenia=RodzajZdarzenia.POZAR,
        wielkosc_pozaru=WielkoscPozaru.MALY,
        # Brak rodzajów MZ - bo niepoprawnie ustawiono P
        gmina="Katowice-Śródmieście",  # Różna od KZ
        kod_obiektu_glowny=209,

        # BŁĄD: czas przybycia < czas zadysponowania
        czas_zauwazenia=datetime(2025, 4, 10, 7, 50),
        czas_zgloszenia=datetime(2025, 4, 10, 8, 0),
        czas_przybycia_pierwszego=datetime(2025, 4, 10, 8, 2),  # OK to
        # BŁĄD: lokalizacja < przybycie? To OK, ale lokalizacja = zakończenie
        czas_lokalizacji=datetime(2025, 4, 10, 8, 30),
        czas_zakonczenia=datetime(2025, 4, 10, 8, 30),  # BŁĄD: == lokalizacja

        # Tlenek węgla w pożarze - BŁĄD
        nr_onz=["1016"],

        # MDR niespójne
        mdr=MDR(
            na_terenie_akcji=3,
            w_tym_przez_strazakow=5,  # BŁĄD: > na_terenie_akcji
            przekazano_joz=2,
            ewakuowano_ze_strefy=10,  # BŁĄD: > na_terenie_akcji
        ),

        # Ofiary śmiertelne bez danych
        wypadki=WypadkiZLudzmi(
            inne_osoby_smiertelne=2,
            w_tym_dzieci_ranne=5,
            inne_osoby_ranne=3,  # BŁĄD: dzieci > inne osoby
        ),
        dane_poszkodowanych=[],  # BŁĄD: brak danych dla ofiar

        # SiS
        sis_jrg={"pojazdy": 4, "osoby": 16},
        sis_osp_ksrg={"pojazdy": 1, "osoby": 4},

        # Brak opisu działań mimo ofiar
        opis_dzialan="",

        # Brak sprzętu MDR mimo MDR > 0
        rodzaj_dzialan=[1, 2, 10],  # Brak działań MDR
        sprzet_ratowniczy=[1, 4],   # Brak sprzętu medycznego
    )

    engine = SWDRulesEngine()

    results_kz = engine.validate_kz(kz)
    print_results(results_kz, "Walidacja Karty Zdarzenia (KZ)")

    results_izz = engine.validate_izz(izz)
    print_results(results_izz, "Walidacja Informacji ze Zdarzenia (IzZ)")

    results_cross = engine.validate_cross(kz, izz)
    print_results(results_cross, "Walidacja krzyżowa KZ ↔ IzZ")

    results_sug = engine.get_suggestions(kz, izz)
    print_results(results_sug, "Podpowiedzi")


def test_suggestion_partial():
    """
    SCENARIUSZ: Podpowiedzi z częściowo wypełnionej KZ.
    Dyżurny dopiero zaczął wypełniać kartę - wypadek drogowy.
    """
    print("\n" + "#" * 70)
    print("  TEST: Podpowiedzi 'w locie' - czesciowa KZ (wypadek drogowy)")
    print("#" * 70)

    kz = KartaZdarzenia(
        rodzaj_zdarzenia=RodzajZdarzenia.MIEJSCOWE_ZAGROZENIE,
        podrodzaj=PodrodzajMZ.WYPADEK.value,
        gmina="Piaseczno",
        jednostka_prowadzaca="JRG-1 Piaseczno",
        czas_przyjecia_zgloszenia=datetime(2025, 5, 1, 17, 45),
        dysponowane_zastepy=[
            {"jednostka": "JRG-1", "pojazd": "GBA",
             "czas_zadysponowania": datetime(2025, 5, 1, 17, 46),
             "obsada": 6},
            {"jednostka": "JRG-1", "pojazd": "SRt",
             "czas_zadysponowania": datetime(2025, 5, 1, 17, 46),
             "obsada": 3},
        ],
    )

    engine = SWDRulesEngine()
    results = engine.get_suggestions(kz=kz)
    print_results(results, "Podpowiedzi dla częściowej KZ")

    # Walidacja też - pokaże brakujące flagi
    results2 = engine.validate_kz(kz)
    print_results(results2, "Walidacja częściowej KZ")


# ============================================================================
# MAIN
# ============================================================================

if __name__ == "__main__":
    test_green_path()
    test_red_path()
    test_suggestion_partial()

    print("\n" + "=" * 70)
    print("  Silnik reguł SWD PSP - demonstracja zakończona.")
    print("=" * 70)

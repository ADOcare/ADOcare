<script setup lang="ts">
import { reactive, computed, watchEffect, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToast } from 'primevue/usetoast'
import { usePatientStore } from '@/stores/patientStore'
import { useAuthStore } from '@/stores/auth'

type Option = { label: string; value: string }

type FieldBase = {
  id: string
  label: string
  help?: string
  required?: boolean
}

type TextField = FieldBase & {
  type: 'text' | 'textarea' | 'date'
  placeholder?: string
}

type RadioField = FieldBase & {
  type: 'radio'
  options: Option[]
}

type CheckboxGroupField = FieldBase & {
  type: 'checkbox-group'
  options: Option[]
}

type AutoCompleteField = FieldBase & {
  type: 'autocomplete'
}

type NursingDiagnosesField = FieldBase & {
  type: 'nursing-diagnoses-autocomplete'
}

interface DiagnosisOption {
  id: number
  code: string
  description: string
}

interface NurseDiagnosis {
  id: number
  code: string
  description: string
}

type Field = TextField | RadioField | CheckboxGroupField | AutoCompleteField | NursingDiagnosesField

type Section = {
  id: string
  title: string
  fields: Field[]
}

type FormSpec = {
  id: string
  title: string
  sections: Section[]
}

const defaultSpec: FormSpec = {
  id: 'admission',
  title: '',
  sections: [
    {
      id: 'basic',
      title: 'Základné údaje',
      fields: [
        { id: 'recommendedPharmacy', label: 'Odporučená farmakoterapia', type: 'textarea' },
        { id: 'admissionDate', label: 'Dátum prijatia do starostlivosti', type: 'date' },
        { id: 'diagnosis', label: 'Diagnóza', type: 'autocomplete' },
      ],
    },
    {
      id: 'allergies',
      title: 'Alergie',
      fields: [
        {
          id: 'allergies',
          label: 'Alergie',
          type: 'checkbox-group',
          options: [
            { label: 'lieky', value: 'medicines' },
            { label: 'potraviny', value: 'food' },
            { label: 'dezinfekčné prípravky', value: 'disinfectants' },
            { label: 'leukoplast', value: 'plaster' },
            { label: 'uštipnutie', value: 'bite' },
          ],
        },
        { id: 'allergies.otherFindings', label: 'Iné zistenia', type: 'textarea' },
      ],
    },
    {
      id: 'abuses',
      title: 'Abúzy',
      fields: [
        {
          id: 'abuses',
          label: 'Abúzy',
          type: 'checkbox-group',
          options: [
            { label: 'kofeín', value: 'caffeine' },
            { label: 'nikotín', value: 'nicotine' },
            { label: 'alkohol', value: 'alcohol' },
            { label: 'lieky', value: 'medicines' },
            { label: 'drogy', value: 'drugs' },
          ],
        },
      ],
    },
    {
      id: 'family',
      title: 'Rodinná anamnéza',
      fields: [
        {
          id: 'familyAnamnesis',
          label: 'Rodinná anamnéza',
          type: 'checkbox-group',
          options: [
            { label: 'IM', value: 'im' },
            { label: 'DM', value: 'dm' },
            { label: 'ICHS', value: 'ichs' },
            { label: 'TBC', value: 'tbc' },
            { label: 'CA', value: 'ca' },
          ],
        },
        { id: 'familyAnamnesis.otherFindings', label: 'Poznámka', type: 'textarea' },
      ],
    },
    {
      id: 'social',
      title: 'Sociálna anamnéza',
      fields: [
        { id: 'employment', label: 'Povolanie', type: 'text' },
        {
          id: 'socialConditions',
          label: 'Sociálne podmienky',
          type: 'checkbox-group',
          options: [
            { label: 'žije sám (a)', value: 'alone' },
            { label: 's rodinou', value: 'with_family' },
            { label: 'v zar. soc. služieb (ZSS)', value: 'zss' },
          ],
        },
        { id: 'socialConditions.otherFindings', label: 'Iné zistenia', type: 'textarea' },
        {
          id: 'socialStatus',
          label: 'Sociálne postavenie',
          type: 'checkbox-group',
          options: [
            { label: 'nezamestnaný', value: 'unemployed' },
            { label: 'zamestnaný', value: 'employed' },
            { label: 'dôchodca', value: 'retired' },
            { label: 'invalidný dôchodca', value: 'disabled_retired' },
            { label: 'MD', value: 'md' },
          ],
        },
        {
          id: 'socialContacts',
          label: 'Sociálny kontakt',
          type: 'radio',
          options: [
            { label: 'deti', value: 'children' },
            { label: 'príbuzní', value: 'relatives' },
          ],
        },
        {
          id: 'supportSystems',
          label: 'Systémy podpory',
          type: 'checkbox-group',
          options: [
            { label: 'priatelia', value: 'friends' },
            { label: 'susedia', value: 'neighbors' },
            { label: 'svojpomocné skupiny', value: 'self_help_groups' },
            { label: 'opatrovateľská služba', value: 'care_service' },
          ],
        },
        {
          id: 'socialCulture',
          label: 'Spoločensko-kultúrna situácia',
          type: 'radio',
          options: [
            { label: 'uprednostňuje samotu', value: 'prefersSolitude' },
            { label: 'spoločnosť', value: 'company' },
          ],
        },
        {
          id: 'socialMedia',
          label: 'Médiá',
          type: 'checkbox-group',
          options: [
            { label: 'TV', value: 'tv' },
            { label: 'rádio', value: 'radio' },
            { label: 'dennú tlač', value: 'newspapers' },
          ],
        },
      ],
    },
    {
      id: 'healthPerception',
      title: 'Vnímanie zdravia',
      fields: [
        { id: 'healthPerception.description', label: 'Subjektívny popis problémov pacienta', type: 'textarea' },
      ],
    },
    {
      id: 'nursingAssessment',
      title: 'Vstupný záznam sesterského posúdenia zdravotného stavu pacienta',
      fields: [
        {
          id: 'nursing.caredRecommendedBy',
          label: 'Staroslivosť odporučil',
          type: 'radio',
          options: [
            { label: 'všeobecný lekár', value: 'general_practitioner' },
            { label: 'lekár LSPP', value: 'lspp_doctor' },
            { label: 'ZZS', value: 'emergency_medical_service' },
          ],
        },
        {
          id: 'nursing.otherDoctor',
          label: 'Iný ošetrujúci lekár',
          type: 'checkbox-group',
          options: [{ label: 'iný ošetrujúci lekár, aký', value: 'otherDoctor' }],
        },
        { id: 'nursing.otherDoctorDetails', label: 'Aký', type: 'text' },
        {
          id: 'nursing.transferredFromOtherFacility',
          label: 'Prevzatý z iného zariadenia',
          type: 'checkbox-group',
          options: [{ label: 'prevzatý z iného zariadenia, odkiaľ', value: 'transferredFrom' }],
        },
        { id: 'nursing.transferredFromOtherFacilityDetails', label: 'Odkiaľ', type: 'text' },
        { id: 'nursing.department', label: 'Oddelenie', type: 'text' },
        { id: 'nursing.lastHospitalizationFrom', label: 'Posledná hospitalizácia od', type: 'date' },
        { id: 'nursing.lastHospitalizationTo', label: 'do', type: 'date' },
      ],
    },
    {
      id: 'consciousnessOrientation',
      title: 'Vedomie a orientácia',
      fields: [
        {
          id: 'consciousness',
          label: 'Vedomie',
          type: 'radio',
          options: [
            { label: 'pri vedomí', value: 'conscious' },
            { label: 'somnolencia', value: 'somnolence' },
            { label: 'semikóma', value: 'semicoma' },
            { label: 'kóma', value: 'coma' },
          ],
        },
        { id: 'consciousnessOtherNotes', label: 'Iné zistenia', type: 'textarea' },
        {
          id: 'orientation',
          label: 'Orientácia v čase a priestore',
          type: 'radio',
          options: [
            { label: 'orientovaný', value: 'oriented' },
            { label: 'dezorientovaný', value: 'disoriented' },
          ],
        },
        { id: 'orientationOtherNotes', label: 'Iné zistenia', type: 'textarea' },
      ],
    },
    {
      id: 'circulation',
      title: 'Cirkulácia',
      fields: [
        { id: 'bloodPressure', label: 'TK (mmHg)', type: 'text' },
        { id: 'temperature', label: 'TT (°C)', type: 'text' },
        { id: 'pulse', label: 'P (/min)', type: 'text' },
        {
          id: 'circulation.problemExists',
          label: 'problém',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'nie', value: 'no' },
          ],
        },
        {
          id: 'hypotensionHypertension',
          label: 'Stav',
          type: 'checkbox-group',
          options: [
            { label: 'hypotenzia', value: 'hypotension' },
            { label: 'hypertenzia', value: 'hypertension' },
          ],
        },
        {
          id: 'irregularPulse',
          label: 'Pulz',
          type: 'checkbox-group',
          options: [
            { label: 'pulz nepravidelný/ slabo hmatný/ nitkovitý', value: 'irregularPulse' },
            { label: 'kardiostimulátor', value: 'cardiacPacemaker' },
          ],
        },
        { id: 'circulation.otherNotes', label: 'Iné zistenia', type: 'textarea' },
      ],
    },
    {
      id: 'breathing',
      title: 'Dýchanie',
      fields: [
        { id: 'respiratoryRate', label: 'D (/min)', type: 'text' },
        {
          id: 'breathing.problemExists',
          label: 'problém',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'nie', value: 'no' },
          ],
        },
        {
          id: 'irregularities',
          label: 'Problémy s dýchaním',
          type: 'checkbox-group',
          options: [
            { label: 'nepravidelné', value: 'irregular' },
            { label: 'rýchle', value: 'fastBreathing' },
            { label: 'pomalé', value: 'slowBreathing' },
            { label: 'sťažené', value: 'difficult' },
            { label: 'plytké', value: 'shallow' },
            { label: 'prehĺbené', value: 'deepened' },
            { label: 'apnoické pauzy', value: 'apneicPauses' },
            { label: 'stridor', value: 'stridor' },
            { label: 'dýchavica v kľude', value: 'dyspneaAtRest' },
            { label: 'kašeľ produktívny/neproduktívny', value: 'cough' },
            { label: 'tracheostómia', value: 'tracheostomy' },
          ],
        },
        { id: 'breathing.otherNotes', label: 'Iné zistenia', type: 'textarea' },
        {
          id: 'suctioning',
          label: 'Odsávanie dýchacích ciest',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'nie', value: 'no' },
          ],
        },
        {
          id: 'oxygenTherapy',
          label: 'Oxygenoterapia',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'nie', value: 'no' },
          ],
        },
        {
          id: 'mechanicalVentilation',
          label: 'UPV',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'nie', value: 'no' },
          ],
        },
        {
          id: 'inhalation',
          label: 'Inhalácia',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'nie', value: 'no' },
          ],
        },
      ],
    },
    {
      id: 'nutrition',
      title: 'Výživa',
      fields: [
        { id: 'nutrition.diet', label: 'Diéta č.', type: 'text' },

        {
          id: 'nutrition.weightTrend',
          label: 'Hmotnosť',
          type: 'radio',
          options: [
            { label: 'prírastok', value: 'increase' },
            { label: 'úbytok', value: 'decrease' },
          ],
        },
        { id: 'nutrition.weightKg', label: 'kg', type: 'text' },

        {
          id: 'nutrition.problemExists',
          label: 'problém',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'nie', value: 'no' },
          ],
        },

        {
          id: 'nutrition.symptoms',
          label: 'Príznaky',
          type: 'checkbox-group',
          options: [
            { label: 'obezita', value: 'obesity' },
            { label: 'kachexia', value: 'cachexia' },
            { label: 'nechutenstvo', value: 'appetiteLoss' },
            { label: 'nauzea', value: 'nausea' },
            { label: 'zvracanie', value: 'vomiting' },
            { label: 'porucha prehĺtania', value: 'swallowingDifficulties' },
            { label: 'pálenie záhy', value: 'heartburn' },
          ],
        },

        {
          id: 'nutrition.feedingType',
          label: 'Typ',
          type: 'radio',
          options: [
            { label: 'enterálne', value: 'enteral' },
            { label: 'nutričné', value: 'nutritional' },
          ],
        },
        { id: 'nutrition.preparations', label: 'prípravky, aké', type: 'text' },

        {
          id: 'nutrition.appetite',
          label: 'Chuť do jedla',
          type: 'radio',
          options: [
            { label: 'priemerná', value: 'average' },
            { label: 'obmedzená', value: 'limited' },
          ],
        },

        {
          id: 'nutrition.intake',
          label: 'Príjem stravy',
          type: 'radio',
          options: [
            { label: 'sám', value: 'alone' },
            { label: 's pomocou', value: 'withHelp' },
            { label: 'nazog. sonda', value: 'tube' },
          ],
        },

        {
          id: 'nutrition.gastrostomy',
          label: 'Gastrostómia',
          type: 'checkbox-group',
          options: [{ label: 'áno', value: 'yes' }],
        },
        { id: 'nutrition.gastrostomyDateIntroduced', label: 'dátum zavedenia', type: 'date' },

        {
          id: 'nutrition.peg',
          label: 'PEG',
          type: 'checkbox-group',
          options: [{ label: 'áno', value: 'yes' }],
        },
        { id: 'nutrition.pegDateIntroduced', label: 'dátum zavedenia', type: 'date' },

        { id: 'nutrition.fluidIntake', label: 'Príjem tekutín (ml/24h)', type: 'text' },

        {
          id: 'nutrition.nutritionRoute',
          label: 'Spôsob výživy',
          type: 'checkbox-group',
          options: [
            { label: 'enterálne', value: 'enteral' },
            { label: 'parenterálne', value: 'parenteral' },
            { label: 'sipping', value: 'sipping' },
          ],
        },

        {
          id: 'nutrition.cvk',
          label: 'CVK',
          type: 'checkbox-group',
          options: [{ label: 'áno', value: 'yes' }],
        },
        { id: 'nutrition.cvkDateIntroduced', label: 'dátum zavedenia', type: 'date' },

        {
          id: 'nutrition.peripheralIVAccess',
          label: 'Periférny i. v. prístup',
          type: 'checkbox-group',
          options: [{ label: 'áno', value: 'yes' }],
        },
        { id: 'nutrition.peripheralIVAccessDateIntroduced', label: 'dátum zavedenia', type: 'date' },

        {
          id: 'nutrition.denture',
          label: 'Kompenzačné pomôcky - zubná protéza',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'nie', value: 'no' },
          ],
        },

        { id: 'nutrition.otherNotes', label: 'Iné zistenia', type: 'textarea' },
      ],
    },
    {
      id: 'elimination',
      title: 'Vylučovanie',
      fields: [
        {
          id: 'defecation.problemExists',
          label: 'Defekácia – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'defecation.symptoms',
          label: '',
          type: 'checkbox-group',
          options: [
            { label: 'nepravidelná', value: 'irregular' },
            { label: 'hnačka', value: 'diarrhea' },
            { label: 'zápcha', value: 'constipation' },
            { label: 's prímesami', value: 'withAdmixtures' },
            { label: 'inkontinencia', value: 'incontinence' },
            { label: 'hemoroidy', value: 'hemorrhoids' },
          ],
        },
        {
          id: 'defecation.stomaCare',
          label: 'Stómia ošetrená naposledy',
          type: 'checkbox-group',
          options: [
            { label: 'stómia', value: 'stoma' },
          ],
        },
        {
          id: 'defecation.stomaCareDate',
          label: 'dátum zavedenia',
          type: 'date',
        },
        {
          id: 'defecation.otherNotes',
          label: 'Iné zistenia',
          type: 'textarea',
        },

        {
          id: 'defecation.stomaAssistanceNeeded',
          label: 'Potreba pomoci pri ošetrovaní stómie',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'nie', value: 'no' },
          ],
        },

        {
          id: 'defecation.regulationUsed',
          label: 'Regulácia vyprázdňovania',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'defecation.regulationMethods',
          label: 'Spôsob regulácie vyprázdňovania',
          type: 'checkbox-group',
          options: [
            { label: 'čaj', value: 'tea' },
            { label: 'čípek', value: 'suppository' },
            { label: 'klyzma', value: 'enema' },
          ],
        },
        {
          id: 'defecation.regulationOtherNotes',
          label: 'Iné zistenia',
          type: 'textarea',
        },

        {
          id: 'urination.diuresis',
          label: 'Diuréza  (ml/24 hod.)',
          type: 'text',
        },
        {
          id: 'urination.problemExists',
          label: 'Močenie – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'urination.symptoms',
          label: '',
          type: 'checkbox-group',
          options: [
            { label: 'dyzúria', value: 'dysuria' },
            { label: 'retencia', value: 'retention' },
            { label: 'inkontinencia', value: 'incontinence' },
            { label: 'absorpčné pomôcky', value: 'absorbentAids' },
          ],
        },
        {
          id: 'urination.catheter',
          label: 'PK',
          type: 'checkbox-group',
          options: [
            { label: 'zavedený', value: 'pkInserted' },
          ],
        },
        {
          id: 'urination.catheterDate',
          label: 'dátum zavedenia',
          type: 'date',
        },
        {
          id: 'urination.urineColor',
          label: 'Farba moču',
          type: 'text',
        },
        {
          id: 'urination.urostomy',
          label: 'Urostómia',
          type: 'checkbox-group',
          options: [
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'urination.urostomyDate',
          label: 'dátum zavedenia',
          type: 'date',
        },
        {
          id: 'urination.dialysis',
          label: 'Dialýza',
          type: 'checkbox-group',
          options: [
            { label: 'peritoneálna', value: 'peritoneal' },
            { label: 'hemodialýza', value: 'hemodialysis' },
          ],
        },
        {
          id: 'urination.dialysisDate',
          label: 'dátum zavedenia',
          type: 'date',
        },
        {
          id: 'urination.condomSystem',
          label: 'Urinárny kondómový systém',
          type: 'checkbox-group',
          options: [
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'urination.otherNotes',
          label: 'Iné zistenia',
          type: 'textarea',
        },
      ],
    },
    {
      id: 'sleep',
      title: 'Spánok',
      fields: [
        {
          id: 'sleep.problemExists',
          label: 'Spánok – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'sleep.findings',
          label: '',
          type: 'checkbox-group',
          options: [
            { label: 'nespavosť', value: 'insomnia' },
            { label: 'nočné budenie', value: 'nightAwakening' },
            { label: 'farmakoterapia', value: 'pharmacotherapy' },
          ],
        },
        { id: 'sleep.otherNotes', label: 'Iné zistenia', type: 'textarea' },
      ],
    },
    {
      id: 'mobility',
      title: 'Mobilita',
      fields: [
        {
          id: 'mobility.level',
          label: 'Mobilita',
          type: 'radio',
          options: [
            { label: '1 – plná mobilita', value: 'full' },
            { label: '2 – mobilita mierne obmedzená', value: 'mildly_limited' },
            { label: '3 – mobilita veľmi obmedzená', value: 'severely_limited' },
            { label: '4 – imobilita', value: 'immobile' },
          ],
        },
        {
          id: 'mobility.compensatoryAids',
          label: 'Kompenzačné pomôcky',
          type: 'checkbox-group',
          options: [
            { label: 'používa', value: 'usesAids' },
          ],
        },
        {
          id: 'mobility.compensatoryAidsDetails',
          label: 'aké',
          type: 'text',
        },
      ],
    },
    {
      id: 'movementSystem',
      title: 'Pohybový systém',
      fields: [
        {
          id: 'movement.problemExists',
          label: 'Pohybový systém – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'movement.findings',
          label: '',
          type: 'checkbox-group',
          options: [
            { label: 'deformácia', value: 'deformity' },
            { label: 'trpnutie končatín', value: 'limbNumbness' },
            { label: 'zlomenina', value: 'fracture' },
            { label: 'ochrnutie', value: 'paralysis' },
            { label: 'amputácia', value: 'amputation' },
          ],
        },
        {
          id: 'movement.otherNotes',
          label: 'Iné zistenia',
          type: 'textarea',
        },
      ],
    },
    {
      id: 'skinMucosa',
      title: 'Koža / Edémy / Sliznice / Hygiena',
      fields: [
        // =====================
        // SKIN
        // =====================
        {
          id: 'skin.problemExists',
          label: 'Koža – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'skin.temperature',
          label: 'Teplota kože',
          type: 'radio',
          options: [
            { label: 'teplá', value: 'warm' },
            { label: 'studená', value: 'cold' },
          ],
        },
        {
          id: 'skin.moisture',
          label: 'Vlhkosť kože',
          type: 'radio',
          options: [
            { label: 'suchá', value: 'dry' },
            { label: 'spotená', value: 'sweaty' },
          ],
        },
        {
          id: 'skin.color',
          label: 'Farba',
          type: 'radio',
          options: [
            { label: 'ružová', value: 'pink' },
            { label: 'bledá', value: 'pale' },
            { label: 'ikterická', value: 'icteric' },
            { label: 'cyanotická', value: 'cyanotic' },
          ],
        },
        {
          id: 'skin.turgor',
          label: 'Turgor',
          type: 'radio',
          options: [
            { label: 'primeraný', value: 'normal' },
            { label: 'znížený', value: 'decreased' },
          ],
        },
        {
          id: 'skin.integrity',
          label: 'Celistvosť kože',
          type: 'radio',
          options: [
            { label: 'nenarušená', value: 'intact' },
            { label: 'narušená', value: 'impaired' },
          ],
        },

        // changes on skin
        {
          id: 'skin.changes',
          label: 'Zmeny na koži',
          type: 'checkbox-group',
          options: [
            { label: 'kožné vyrážky', value: 'rash' },
            { label: 'svrbenie', value: 'itching' },
            { label: 'olupovanie', value: 'peeling' },
            { label: 'zaparenia', value: 'chafing' },
            { label: 'modriny', value: 'bruises' },
            { label: 'zápal', value: 'inflammation' },

            { label: 'povrchové poranenie', value: 'superficialInjury' },
            { label: 'otvorená rana', value: 'openWound' },
            { label: 'operačná rana', value: 'surgicalWound' },
            { label: 'abdominálna', value: 'abdominal' },
            { label: 'vaginálna', value: 'vaginal' },

            { label: 'krvácanie', value: 'bleeding' },
            { label: 'ulcus cruris', value: 'ulcusCruris' },
            { label: 'gangréna', value: 'gangrene' },
            { label: 'dekubity', value: 'pressureUlcers' },
          ],
        },

        { id: 'skin.defectLocation', label: 'Lokalizácia', type: 'text' },
        { id: 'skin.defectSizeCm', label: 'Veľkosť defektu (cm)', type: 'text' },

        { id: 'skin.patientDayAfterSurgery', label: 'Pacient/pacientka je – deň po operácii', type: 'text' },

        // =====================
        // EDEMA
        // =====================
        {
          id: 'edema.problemExists',
          label: 'Edémy – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'edema.type',
          label: 'Typ edému',
          type: 'radio',
          options: [
            { label: 'miestne', value: 'local' },
            { label: 'celkové', value: 'general' },
          ],
        },
        {
          id: 'edema.measures',
          label: 'Opatrenia',
          type: 'checkbox-group',
          options: [
            { label: 'bandáž DK', value: 'lowerLimbBandage' },
            { label: 'antitrombotické pančuchy', value: 'antithromboticStockings' },
            { label: 'cievna gymnastika', value: 'vascularExercises' },
          ],
        },

        // =====================
        // MUCOUS MEMBRANES
        // =====================
        {
          id: 'mucosa.problemExists',
          label: 'Sliznice – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'mucosa.findings',
          label: '',
          type: 'checkbox-group',
          options: [
            { label: 'neprekrvené', value: 'notCongested' },
            { label: 'krvácanie', value: 'bleeding' },
            { label: 'infekcia', value: 'infection' },
            { label: 'zmeny na sliznici dutiny ústnej', value: 'oralMucosaChanges' },
          ],
        },

        // =====================
        // HYGIENE
        // =====================
        {
          id: 'hygiene.statusOnAdmission',
          label: 'Hygienický stav pri prijatí',
          type: 'radio',
          options: [
            { label: 'primeraný', value: 'adequate' },
            { label: 'zanedbaný', value: 'neglected' },
          ],
        },
        {
          id: 'hygiene.selfCare',
          label: 'Hygienickú starostlivosť vykonáva',
          type: 'radio',
          options: [
            { label: 'samostatne', value: 'independent' },
            { label: 's pomocou', value: 'withHelp' },
            { label: 'je úplne závislý (á)', value: 'fullyDependent' },
          ],
        },

        { id: 'skinMucosa.otherNotes', label: 'Iné zistenia', type: 'textarea' },
      ],
    },
    {
      id: 'postpartum',
      title: 'Pôrodné posúdenie (šestonedelie)',
      fields: [
        // =====================
        // PARITY + DELIVERY
        // =====================
        {
          id: 'postpartum.parity',
          label: 'Poradie pôrodu',
          type: 'radio',
          options: [
            { label: 'prvorodička', value: 'primipara' },
            { label: 'druhorodička', value: 'secundipara' },
            { label: 'viacrodička', value: 'multipara' },
          ],
        },
        { id: 'postpartum.deliveryDate', label: 'Dátum pôrodu', type: 'date' },

        {
          id: 'postpartum.deliveryType',
          label: 'Pôrod',
          type: 'radio',
          options: [
            { label: 'spontánny', value: 'spontaneous' },
            { label: 'operatívny', value: 'operative' },
          ],
        },

        // =====================
        // COMPLICATIONS
        // =====================
        {
          id: 'postpartum.complications',
          label: 'Komplikácie po pôrode',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'postpartum.complicationDetails',
          label: 'Charakter komplikácií',
          type: 'text',
        },

        // =====================
        // UTERUS / BLEEDING
        // =====================
        {
          id: 'postpartum.fundusUteri',
          label: 'Fundus maternice',
          type: 'text',
        },
        {
          id: 'postpartum.lochiaAppearance',
          label: 'Stav krvácania / lochie – vzhľad',
          type: 'text',
        },
        {
          id: 'postpartum.lochiaAmount',
          label: 'Množstvo',
          type: 'text',
        },

        // =====================
        // WOUND HEALING
        // =====================
        {
          id: 'postpartum.woundHealing',
          label: 'Hojenie popôrodného poranenia',
          type: 'radio',
          options: [
            { label: 'per primam', value: 'perPrimam' },
            { label: 'per sekundam', value: 'perSekundam' },
          ],
        },

        // =====================
        // BREASTS / LACTATION
        // =====================
        {
          id: 'postpartum.breasts',
          label: 'Prsníky',
          type: 'checkbox-group',
          options: [
            { label: 'voľné', value: 'soft' },
            { label: 'začervenanie', value: 'redness' },
            { label: 'trhlinky bradaviek', value: 'nippleCracks' },
            { label: 'bolestivosť', value: 'painful' },
            { label: 'retencia mlieka', value: 'milkRetention' },
          ],
        },

        {
          id: 'postpartum.lactation',
          label: 'Laktácia rozvinutá',
          type: 'radio',
          options: [
            { label: 'áno', value: 'yes' },
            { label: 'čiastočne', value: 'partial' },
            { label: 'nie', value: 'no' },
          ],
        },

        // =====================
        // NEWBORN
        // =====================
        {
          id: 'postpartum.newbornSex',
          label: 'Novorodenec',
          type: 'radio',
          options: [
            { label: 'chlapec', value: 'male' },
            { label: 'dievča', value: 'female' },
          ],
        },
        { id: 'postpartum.newbornWeight', label: 'Pôrodná hmotnosť (g)', type: 'text' },
        { id: 'postpartum.newbornLength', label: 'Dĺžka (cm)', type: 'text' },
        { id: 'postpartum.newbornHeadCircumference', label: 'Obvod hlavy (cm)', type: 'text' },
        { id: 'postpartum.newbornChestCircumference', label: 'Obvod hrudníka (cm)', type: 'text' },

        // =====================
        // NOTES
        // =====================
        { id: 'postpartum.otherNotes', label: 'Iné zistenia', type: 'textarea' },
      ],
    },
    {
      id: 'pain',
      title: 'Bolesť',
      fields: [
        {
          id: 'pain.problemExists',
          label: 'Bolesť – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'pain.type',
          label: 'Typ bolesti',
          type: 'radio',
          options: [
            { label: 'akútna', value: 'acute' },
            { label: 'chronická', value: 'chronic' },
          ],
        },
        {
          id: 'pain.location',
          label: 'Lokalizácia',
          type: 'text',
        },
        {
          id: 'pain.character',
          label: 'Charakter',
          type: 'text',
        },
        {
          id: 'pain.otherNotes',
          label: 'Iné zistenia',
          type: 'textarea',
        },
      ],
    },
    {
      id: 'communication',
      title: 'Komunikácia',
      fields: [
        {
          id: 'communication.type',
          label: 'Spôsob komunikácie',
          type: 'radio',
          options: [
            { label: 'verbálna', value: 'verbal' },
            { label: 'neverbálna', value: 'nonverbal' },
          ],
        },
        {
          id: 'communication.problemExists',
          label: 'Komunikácia – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'communication.issues',
          label: '',
          type: 'checkbox-group',
          options: [
            { label: 'poruchy reči', value: 'speechDisorders' },
            { label: 'nemožná', value: 'impossible' },
          ],
        },
        {
          id: 'communication.otherNotes',
          label: 'Iné zistenia',
          type: 'textarea',
        },
      ],
    },
    {
      id: 'learningPerception',
      title: 'Učenie, zmyslové vnímanie',
      fields: [
        // =====================
        // PROBLEM
        // =====================
        {
          id: 'learning.problemExists',
          label: 'Učenie / zmyslové vnímanie – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },

        // =====================
        // SENSORY CHANGES
        // =====================
        {
          id: 'learning.sensoryChanges',
          label: 'Zmeny v zmysloch',
          type: 'checkbox-group',
          options: [
            { label: 'zrak', value: 'vision' },
            { label: 'sluch', value: 'hearing' },
            { label: 'reč', value: 'speech' },
          ],
        },
        {
          id: 'learning.sensoryChangesExist',
          label: 'Zmeny v zmysloch prítomné',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'learning.sensoryChangesDetails',
          label: 'aké',
          type: 'text',
        },

        // =====================
        // COMPENSATORY AIDS
        // =====================
        {
          id: 'learning.compensatoryAids',
          label: 'Kompenzačné pomôcky',
          type: 'checkbox-group',
          options: [
            { label: 'okuliare', value: 'glasses' },
            { label: 'šošovky', value: 'contactLenses' },
            { label: 'načúvací aparát', value: 'hearingAid' },
          ],
        },

        // =====================
        // KNOWLEDGE ABOUT DISEASE
        // =====================
        {
          id: 'learning.diseaseKnowledge',
          label: 'Vedomosti o chorobe',
          type: 'radio',
          options: [
            { label: 'dostatok', value: 'sufficient' },
            { label: 'nedostatok', value: 'insufficient' },
          ],
        },

        // =====================
        // PATIENT EDUCATION
        // =====================
        {
          id: 'learning.educationTopics',
          label: 'Edukácia pacienta/pacientky',
          type: 'checkbox-group',
          options: [
            {
              label: 'o ošetrovateľskej starostlivosti v domácom prostredí',
              value: 'homeCare',
            },
            {
              label: 'o ošetrovateľskej starostlivosti v pooperačnom období',
              value: 'postoperativeCare',
            },
            {
              label: 'o ošetrovateľskej starostlivosti v šestonedelí',
              value: 'postpartumCare',
            },
            {
              label: 'o paliatívnej starostlivosti',
              value: 'palliativeCare',
            },
            {
              label: 'o ošetrovateľskej starostlivosti po chemoterapii',
              value: 'postChemotherapyCare',
            },
          ],
        },

        { id: 'learning.otherNotes', label: 'Iné zistenia', type: 'textarea' },
      ],
    },
    {
      id: 'psychosocialNeeds',
      title: 'Psychické, sociálne a duchovné potreby',
      fields: [
        // =====================
        // PSYCHICAL NEEDS
        // =====================
        {
          id: 'psychological.problemExists',
          label: 'Psychické potreby – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'psychological.mood',
          label: 'Nálada',
          type: 'radio',
          options: [
            { label: 'primeraná', value: 'adequate' },
            { label: 'apatia', value: 'apathy' },
            { label: 'depresia', value: 'depression' },
            { label: 'eufória', value: 'euphoria' },
            { label: 'agresia', value: 'aggression' },
          ],
        },

        // =====================
        // FEELING OF SAFETY / EMOTIONS
        // =====================
        {
          id: 'psychological.feelings',
          label: 'Istota, bezpečie',
          type: 'checkbox-group',
          options: [
            { label: 'kľudný', value: 'calm' },
            { label: 'vyrovnaný', value: 'balanced' },
            { label: 'strach', value: 'fear' },
            { label: 'smútok', value: 'sadness' },
            { label: 'úzkosť', value: 'anxiety' },
            { label: 'hnev', value: 'anger' },
            { label: 'depresia', value: 'depression' },
            { label: 'beznádej', value: 'hopelessness' },
            { label: 'bezmocnosť', value: 'helplessness' },
            { label: 'zmätenosť', value: 'confusion' },
            { label: 'sebaobviňovanie', value: 'selfBlame' },
            { label: 'sebaľútovanie', value: 'selfPity' },
          ],
        },

        // =====================
        // SOCIAL NEEDS
        // =====================
        {
          id: 'social.problemExists',
          label: 'Sociálne potreby – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },
        {
          id: 'social.supportDependency',
          label: 'Sociálna pomoc',
          type: 'radio',
          options: [
            { label: 'je odkázaný (á) na sociálnu pomoc', value: 'dependent' },
            { label: 'nie odkázaný (á) na sociálnu pomoc', value: 'independent' },
          ],
        },

        // =====================
        // SPIRITUAL NEEDS
        // =====================
        {
          id: 'spiritual.problemExists',
          label: 'Duchovné potreby – problém',
          type: 'radio',
          options: [
            { label: 'nie', value: 'no' },
            { label: 'áno', value: 'yes' },
          ],
        },

        // =====================
        // NOTES
        // =====================
        {
          id: 'psychosocial.otherNotes',
          label: 'Iné zistenia',
          type: 'textarea',
        },
      ],
    },
    {
      id: 'careDeficits',
      title: 'Nedostatočnosť v oblasti',
      fields: [
        // =====================
        // DEFICITS IN AREAS
        // =====================
        {
          id: 'deficits.areas',
          label: 'Nedostatočnosť v oblasti',
          type: 'checkbox-group',
          options: [
            { label: 'výživy', value: 'nutrition' },
            { label: 'vyprázdňovania', value: 'elimination' },
            { label: 'hygieny', value: 'hygiene' },
            { label: 'obliekania', value: 'dressing' },
          ],
        },

        // =====================
        // TREATMENT / INTERVENTIONS
        // =====================
        {
          id: 'deficits.treatments',
          label: 'Liečba / intervencie',
          type: 'checkbox-group',
          options: [
            { label: 'chron. liečby', value: 'chronicTreatment' },
            { label: 'aplik. INZ, s.c. inj.', value: 'insulinSubcutaneous' },
            { label: 'pod. liekov per os', value: 'oralMedication' },
            { label: 'eliminácie bolesti', value: 'painElimination' },
          ],
        },

        // =====================
        // NURSING CARE
        // =====================
        {
          id: 'deficits.nursingCare',
          label: 'Starostlivosť o',
          type: 'checkbox-group',
          options: [
            { label: 'ranu', value: 'woundCare' },
            { label: 'stómiu', value: 'stomaCare' },
            { label: 'dekubit', value: 'pressureUlcerCare' },
            { label: 'ranu hojaciu sa per sekundam', value: 'secondaryHealingWound' },
            { label: 'hrádzu', value: 'perineumCare' },
            { label: 'prsníky', value: 'breastCare' },
            { label: 'dojčenie', value: 'breastfeedingSupport' },
            { label: 'novorodenca', value: 'newbornCare' },
          ],
        },

        // =====================
        // NOTES
        // =====================
        {
          id: 'careDeficits.otherNotes',
          label: 'Iné zistenia',
          type: 'textarea',
        },
      ],
    },
    {
      id: 'patientInstruction',
      title: 'Poučenie pacienta/pacientky',
      fields: [
        // =====================
        // PATIENT INSTRUCTION
        // =====================
        {
          id: 'instruction.topics',
          label: 'Pacient/pacientka je poučený(á) o',
          type: 'checkbox-group',
          options: [
            {
              label: 'právach a povinnostiach hospitalizovaných pacientov',
              value: 'rightsAndDuties',
            },
            {
              label: 'úschove peňazí/cenností',
              value: 'valuablesStorage',
            },
            {
              label: 'domácom poriadku',
              value: 'houseRules',
            },
            {
              label: 'zákaze fajčenia, užívania alkoholu, drog',
              value: 'prohibitions',
            },
          ],
        },

        // =====================
        // HANDOVER ON ADMISSION
        // =====================
        {
          id: 'instruction.handoverOnAdmission',
          label: 'Pacient/pacientka pri prijatí odovzdal(a)',
          type: 'textarea',
        },

        // =====================
        // DATE & SIGNATURE
        // =====================
        {
          id: 'instruction.date',
          label: 'Dátum',
          type: 'date',
        },
      ],
    },
    {
      id: 'nursingDiagnoses',
      title: 'Stanovenie sesterských diagnóz pri príjme',
      fields: [
        {
          id: 'nursingDiagnoses.list',
          label: 'Sesterské diagnózy pri príjme',
          type: 'nursing-diagnoses-autocomplete',
        },
        {
          id: 'nursingDiagnoses.dateTime',
          label: 'Dátum',
          type: 'date',
        },
      ],
    }
  ],
}

/**
 * Answers live in one object.
 * Convention:
 * - text/textarea/date => string
 * - radio => string | null
 * - checkbox-group => string[]
 */
const answers = reactive<Record<string, any>>({
  // basic
  diagnosis: [] as DiagnosisOption[],
  recommendedPharmacy: '',
  admissionDate: '',

  // allergies
  allergies: [],
  'allergies.otherFindings': '',

  // abuses
  abuses: [],

  // family
  familyAnamnesis: [],
  'familyAnamnesis.otherFindings': '',

  // social
  employment: '',
  socialConditions: [],
  'socialConditions.otherFindings': '',
  socialStatus: [],
  socialContacts: null,
  supportSystems: [],
  socialCulture: null,
  socialMedia: [],

  // health perception
  'healthPerception.description': '',

  // nursing assessment
  'nursing.caredRecommendedBy': null,
  'nursing.otherDoctor': [],
  'nursing.otherDoctorDetails': '',
  'nursing.transferredFromOtherFacility': [],
  'nursing.transferredFromOtherFacilityDetails': '',
  'nursing.department': '',
  'nursing.lastHospitalizationFrom': '',
  'nursing.lastHospitalizationTo': '',

  // consciousness & orientation
  consciousness: null,
  consciousnessOtherNotes: '',
  orientation: null,
  orientationOtherNotes: '',

  // circulation
  bloodPressure: '',
  temperature: '',
  pulse: '',
  'circulation.problemExists': null,
  hypotensionHypertension: [],
  irregularPulse: [],
  'circulation.otherNotes': '',

  // breathing
  respiratoryRate: '',
  'breathing.problemExists': null,
  irregularities: [],
  'breathing.otherNotes': '',
  suctioning: null,
  oxygenTherapy: null,
  mechanicalVentilation: null,
  inhalation: null,

  // nutrition
  'nutrition.diet': '',
  'nutrition.weightTrend': null,
  'nutrition.weightKg': '',
  'nutrition.problemExists': null,
  'nutrition.symptoms': [],
  'nutrition.feedingType': null,
  'nutrition.preparations': '',
  'nutrition.appetite': null,
  'nutrition.intake': null,


  'nutrition.gastrostomy': [],
  'nutrition.gastrostomyDateIntroduced': '',

  'nutrition.peg': [],
  'nutrition.pegDateIntroduced': '',

  'nutrition.fluidIntake': '',
  'nutrition.nutritionRoute': [],

  'nutrition.cvk': [],
  'nutrition.cvkDateIntroduced': '',

  'nutrition.peripheralIVAccess': [],
  'nutrition.peripheralIVAccessDateIntroduced': '',

  'nutrition.denture': null,
  'nutrition.otherNotes': '',

  // elimination – defecation
  'defecation.problemExists': null,
  'defecation.symptoms': [],
  'defecation.stomaCare': [],
  'defecation.stomaCareDate': '',
  'defecation.otherNotes': '',
  'defecation.stomaAssistanceNeeded': null,
  'defecation.regulationUsed': null,
  'defecation.regulationMethods': [],
  'defecation.regulationOtherNotes': '',

  // elimination – urination
  'urination.diuresis': '',
  'urination.problemExists': null,
  'urination.symptoms': [],
  'urination.catheter': [],
  'urination.catheterDate': '',
  'urination.urineColor': '',
  'urination.urostomy': [],
  'urination.urostomyDate': '',
  'urination.dialysis': [],
  'urination.dialysisDate': '',
  'urination.condomSystem': [],
  'urination.otherNotes': '',

  'sleep.problemExists': null,
  'sleep.findings': [],
  'sleep.otherNotes': '',

  // mobility
  'mobility.level': null,
  'mobility.compensatoryAids': [],
  'mobility.compensatoryAidsDetails': '',

  // movement system
  'movement.problemExists': null,
  'movement.findings': [],
  'movement.otherNotes': '',

  // skin / mucosa / hygiene
  'skin.problemExists': null,
  'skin.temperature': null,
  'skin.moisture': null,
  'skin.color': null,
  'skin.turgor': null,
  'skin.integrity': null,
  'skin.changes': [],
  'skin.defectLocation': '',
  'skin.defectSizeCm': '',
  'skin.patientDayAfterSurgery': '',

  'edema.problemExists': null,
  'edema.type': null,
  'edema.measures': [],

  'mucosa.problemExists': null,
  'mucosa.findings': [],

  'hygiene.statusOnAdmission': null,
  'hygiene.selfCare': null,

  'skinMucosa.otherNotes': '',

  // postpartum
  'postpartum.parity': null,
  'postpartum.deliveryDate': '',
  'postpartum.deliveryType': null,

  'postpartum.complications': null,
  'postpartum.complicationDetails': '',

  'postpartum.fundusUteri': '',
  'postpartum.lochiaAppearance': '',
  'postpartum.lochiaAmount': '',

  'postpartum.woundHealing': null,

  'postpartum.breasts': [],
  'postpartum.lactation': null,

  'postpartum.newbornSex': null,
  'postpartum.newbornWeight': '',
  'postpartum.newbornLength': '',
  'postpartum.newbornHeadCircumference': '',
  'postpartum.newbornChestCircumference': '',

  'postpartum.otherNotes': '',


  // pain
  'pain.problemExists': null,
  'pain.type': null,
  'pain.location': '',
  'pain.character': '',
  'pain.otherNotes': '',

  // communication
  'communication.type': null,
  'communication.problemExists': null,
  'communication.issues': [],
  'communication.otherNotes': '',

  // learning & perception
  'learning.problemExists': null,
  'learning.sensoryChanges': [],
  'learning.sensoryChangesExist': null,
  'learning.sensoryChangesDetails': '',
  'learning.compensatoryAids': [],
  'learning.diseaseKnowledge': null,
  'learning.educationTopics': [],
  'learning.otherNotes': '',

  // psychological
  'psychological.problemExists': null,
  'psychological.mood': null,
  'psychological.feelings': [],

  // social
  'social.problemExists': null,
  'social.supportDependency': null,

  // spiritual
  'spiritual.problemExists': null,

  // notes
  'psychosocial.otherNotes': '',

  // care deficits
  'deficits.areas': [],
  'deficits.treatments': [],
  'deficits.nursingCare': [],
  'careDeficits.otherNotes': '',

  // patient instruction
  'instruction.topics': [],
  'instruction.handoverOnAdmission': '',
  'instruction.date': '',

  // nursing diagnoses
  'nursingDiagnoses.list': [] as NurseDiagnosis[],
  'nursingDiagnoses.dateTime': '',
})

const filteredDiagnoses = ref<DiagnosisOption[]>([])
const filteredNurseDiagnoses = ref<NurseDiagnosis[]>([])

const router = useRouter()
const toast = useToast()
const patientStore = usePatientStore()
const authStore = useAuthStore()

patientStore.loadFromStorage?.()

const patientId = computed(() => patientStore.current?.id ?? 0)
const patientDeathDate = computed<Date | null>(() => {
  const raw = patientStore.current?.death_date
  if (!raw || typeof raw !== 'string') return null

  const d = new Date(`${raw.slice(0, 10)}T00:00:00`)
  if (isNaN(d.getTime())) return null
  return new Date(d.getFullYear(), d.getMonth(), d.getDate())
})

function extractArray(raw: any): any[] {
  if (Array.isArray(raw)) return raw

  const candidates = [
    raw?.data,
    raw?.data?.items,
    raw?.data?.data,
    raw?.data?.data?.items,
    raw?.data?.data?.data,
    raw?.items,
    raw?.items?.data
  ]

  for (const c of candidates) {
    if (Array.isArray(c)) return c
  }
  return []
}

async function searchDiagnoses(event: { query: string }) {
  try {
    const q = (event.query ?? '').trim()

    const res = await api.get('/v1/diagnoses', {
      params: { q, per_page: 25, page: 1, sort: 'code' }
    })

    const arr = extractArray(res.data) as DiagnosisOption[]
    filteredDiagnoses.value = arr.map((d: any) => ({
      id: d.id,
      code: d.code ?? '',
      description: d.description ?? ''
    }))
  } catch (e) {
    console.error('Failed to load diagnoses', e)
    filteredDiagnoses.value = []
  }
}

function parseCodeFromText(v: string): string {
  return (String(v ?? '').split(' - ')[0] ?? '').trim()
}

async function fetchDiagnosisByCode(code: string): Promise<DiagnosisOption | null> {
  if (!code) return null
  const res = await api.get('/v1/diagnoses', { params: { q: code, per_page: 50, page: 1, sort: 'code' } })
  const arr = extractArray(res.data) as DiagnosisOption[]
  const match = arr.find(d => (d.code ?? '').toLowerCase() === code.toLowerCase())
  return match ? { id: match.id, code: match.code ?? '', description: match.description ?? '' } : null
}

async function fetchNurseDiagnosisByCode(code: string): Promise<NurseDiagnosis | null> {
  if (!code) return null
  const res = await api.get('/v1/nurse-diagnoses', { params: { q: code, per_page: 50, page: 1, sort: 'code', paginate: 0 } })
  const arr = extractArray(res.data) as NurseDiagnosis[]
  const match = arr.find(d => (d.code ?? '').toLowerCase() === code.toLowerCase())
  return match ? { id: match.id, code: match.code ?? '', description: match.description ?? '' } : null
}

function removeDoctorDiagnosis(d: DiagnosisOption) {
  answers.diagnosis = (answers.diagnosis ?? []).filter((x: DiagnosisOption) => x.id !== d.id)
}

function removeNurseDiagnosis(d: NurseDiagnosis) {
  answers['nursingDiagnoses.list'] = (answers['nursingDiagnoses.list'] ?? []).filter((x: NurseDiagnosis) => x.id !== d.id)
}


async function searchNurseDiagnoses(event: { query: string }) {
  try {
    const q = (event.query ?? '').trim()

    const res = await api.get('/v1/nurse-diagnoses', {
      params: { q, per_page: 25, page: 1, sort: 'code', paginate: 0 }
    })

    const arr = extractArray(res.data) as NurseDiagnosis[]
    filteredNurseDiagnoses.value = arr.map((d: any) => ({
      id: d.id,
      code: d.code ?? '',
      description: d.description ?? ''
    }))
  } catch (e) {
    console.error('Failed to load nurse diagnoses', e)
    filteredNurseDiagnoses.value = []
  }
}

async function preloadFromLatestRecord() {
  if (!patientId.value) return

  try {
    const res = await api.get(`/v1/patients/${patientId.value}/records/latest`)
    const recordData = res?.data?.data?.record_data?.form_data ?? res?.data?.record_data?.form_data

    if (!recordData) return

    // Populate all answers from the saved record data (skip diagnosis and nursing diagnoses - they need special handling)
    Object.keys(recordData).forEach((key) => {
      if (key in answers && key !== 'diagnosis' && key !== 'nursingDiagnoses.list') {
        answers[key] = recordData[key]
      }
    })

    if (recordData.diagnosis) {
      const raw = recordData.diagnosis

      const rawList: any[] = Array.isArray(raw) ? raw : [raw]

      const mapped = await Promise.all(
        rawList
          .filter(Boolean)
          .map(async (item: any) => {
            // already object
            if (typeof item === 'object' && item.id) {
              return {
                id: item.id,
                code: item.code ?? '',
                description: item.description ?? ''
              } as DiagnosisOption
            }

            // string (maybe "CODE - desc")
            if (typeof item === 'string') {
              const code = parseCodeFromText(item)
              return await fetchDiagnosisByCode(code)
            }

            return null
          })
      )

      answers.diagnosis = mapped.filter((x): x is DiagnosisOption => x !== null)
    }

    if (recordData['nursingDiagnoses.list']) {
      const raw = recordData['nursingDiagnoses.list']

      const rawList: any[] = Array.isArray(raw) ? raw : [raw]

      const mapped = await Promise.all(
        rawList
          .filter(Boolean)
          .map(async (item: any) => {
            if (typeof item === 'object' && item.id) {
              return {
                id: item.id,
                code: item.code ?? '',
                description: item.description ?? ''
              } as NurseDiagnosis
            }

            if (typeof item === 'string') {
              const code = parseCodeFromText(item)
              return await fetchNurseDiagnosisByCode(code)
            }

            return null
          })
      )

      answers['nursingDiagnoses.list'] = mapped.filter((x): x is NurseDiagnosis => x !== null)
    }

    toast.add({
      severity: 'info',
      summary: 'Načítané',
      detail: 'Texty načítané z histórie.',
      life: 2500,
    })
  } catch (e: any) {
    if (e?.response?.status !== 404) {
      console.error('Prefill failed:', e)
    }
    // 404 is expected for new records, so we don't show an error
  }
}

watchEffect(() => {
  if (patientId.value) {
    preloadFromLatestRecord()
  }
})

async function saveRecord() {
  try {
    const admissionDate = toIso(getValue('admissionDate'))
    const branchId = authStore.currentBranch?.id ?? patientStore.current?.branch_id ?? null

    const payload = {
      patient_id: patientId.value,
      branch_id: branchId,
      date: admissionDate || null,
      form_spec: defaultSpec,
      record_data: {
        ...answers,
        admissionDate,
      },
    }

    const res = await api.post('/v1/records', payload)

    toast.add({
      severity: 'success',
      summary: 'Úspešne',
      detail: 'Ošetrovateľský záznam bol vytvorený',
      life: 3000
    })

    const documentId = res.data?.data?.document_id ?? res.data?.document_id ?? null
    if (documentId) {
      router.push({ name: 'documents-record', params: { documentId } })
    }
  } catch (err: any) {
    console.error('Failed to save record:', err)
    const message = err?.response?.data?.message || err?.message || 'Chyba pri vytváraní záznamu'
    toast.add({ severity: 'error', summary: 'Chyba', detail: message, life: 3000 })
  }
}

function answersGetValue(id: string) {
  return answers[id]
}

function answersSetValue(id: string, value: any) {
  answers[id] = value
}

function toggleInArray(id: string, value: string, checked: boolean) {
  const arr = Array.isArray(answers[id]) ? [...answers[id]] : []
  const i = arr.indexOf(value)
  if (checked && i === -1) arr.push(value)
  if (!checked && i !== -1) arr.splice(i, 1)
  answers[id] = arr
}

const props = defineProps<{
  spec: any
  getValue: (id: string) => any
  setValue: (id: string, v: any) => void
  outputJson: string
  submitted?: boolean
  errors?: Record<string, string>
}>()

// public helpers used in template: prefer props when passed, otherwise use internal answers
const getValue = (id: string) => {
  if (id === 'diagnosis') {
    return answers.diagnosis
  }
  return props.getValue ? props.getValue(id) : answersGetValue(id)
}
const setValue = (id: string, v: any) => {
  if (id === 'diagnosis') {
    answers.diagnosis = v
    return
  }
  return props.setValue ? props.setValue(id, v) : answersSetValue(id, v)
}
const displaySpec = computed(() => props.spec ?? defaultSpec)
const dateFieldIds = computed(() => {
  const ids = new Set<string>()
  for (const s of displaySpec.value.sections ?? []) {
    for (const f of s.fields ?? []) {
      if (f.type === 'date') ids.add(f.id)
    }
  }
  return ids
})

/**
 * DatePicker wants Date | null, but you store strings.
 * This proxy converts between your stored value and DatePicker.
 */
const dateProxy = reactive<Record<string, any>>({})

const toDate = (v: any) => {
  if (!v) return null
  // accept "YYYY-MM-DD" or Date
  if (v instanceof Date) return v
  const d = new Date(v)
  return isNaN(d.getTime()) ? null : d
}

const toIsoUnclamped = (d: Date) => {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

const clampDateToDeath = (d: Date | null) => {
  if (!d) return null
  const normalized = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  if (!patientDeathDate.value) return normalized
  return normalized > patientDeathDate.value ? patientDeathDate.value : normalized
}

const toIso = (d: any) => {
  if (!d) return ''
  const raw = d instanceof Date ? d : new Date(d)
  const dt = clampDateToDeath(raw)
  if (!dt) return ''
  if (isNaN(dt.getTime())) return ''
  // YYYY-MM-DD
  const y = dt.getFullYear()
  const m = String(dt.getMonth() + 1).padStart(2, '0')
  const day = String(dt.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

const bindDateField = (fieldId: string) => {
  if (fieldId in dateProxy) return

  Object.defineProperty(dateProxy, fieldId, {
    get: () => clampDateToDeath(toDate(getValue(fieldId))),
    set: (val: any) => setValue(fieldId, toIso(val)),
    enumerable: true,
    configurable: true,
  })
}

watchEffect(() => {
  for (const fieldId of dateFieldIds.value) {
    bindDateField(fieldId)
  }
})

watchEffect(() => {
  if (!patientDeathDate.value) return
  for (const fieldId of dateFieldIds.value) {
    const current = toDate(getValue(fieldId))
    const clamped = clampDateToDeath(current)
    if (!current || !clamped) continue
    if (toIsoUnclamped(current) !== toIsoUnclamped(clamped)) {
      setValue(fieldId, toIso(clamped))
    }
  }
})

</script>

<template>
  <div class="flex flex-col gap-6">
    <h2 class="text-xl font-semibold">{{ displaySpec.title }}</h2>

    <section
      v-for="section in displaySpec.sections"
      :key="section.id"
      class="bg-tag3 p-6 rounded-md flex flex-col gap-6"
    >
      <h3 class="text-accent text-normal">{{ section.title }}</h3>

      <div v-for="field in section.fields" :key="field.id" class="flex flex-col gap-2">
        <!-- Hide label row if empty -->
        <label v-if="field.label?.trim()" class="block text-normal">
          {{ field.label }}
          <span v-if="field.required" class="text-red-600">*</span>
        </label>

        <!-- text -->
        <input
          v-if="field.type === 'text'"
          class="w-full rounded-md bg-white px-3 py-2 border-0 shadow-none outline-none focus:ring-0 focus:shadow-none text-normal"
          :placeholder="field.placeholder"
          :value="getValue(field.id) ?? ''"
          @input="setValue(field.id, ($event.target as HTMLInputElement).value)"
        />

        <!-- textarea -->
        <textarea
          v-else-if="field.type === 'textarea'"
          class="w-full rounded-md bg-white px-3 py-2 border-0 shadow-none outline-none focus:ring-0 focus:shadow-none text-normal"
          rows="4"
          :placeholder="field.placeholder"
          :value="getValue(field.id) ?? ''"
          @input="setValue(field.id, ($event.target as HTMLTextAreaElement).value)"
        />

        <!-- date -->
        <DatePicker
          v-else-if="field.type === 'date'"
          v-model="dateProxy[field.id]"
          dateFormat="dd.mm.yy"
          :showIcon="false"
          :maxDate="patientDeathDate || undefined"
          class="w-full"
          inputClass="!w-full !shadow-none !bg-white focus:!ring-0 focus:!shadow-none !border-0 text-normal"
        />

        <!-- autocomplete -->
        <AutoComplete
          v-else-if="field.type === 'autocomplete'"
          v-model="answers.diagnosis"
          :suggestions="filteredDiagnoses"
          multiple
          :minLength="1"
          dropdown
          completeOnFocus
          optionLabel="code"
          @complete="searchDiagnoses"
          class="w-full"
          inputClass="w-full! shadow-none! bg-white! focus:ring-0! focus:shadow-none! border-0! text-normal"
        >
          <template #option="{ option }">
            <div class="flex flex-col">
              <span class="shrink-0 font-medium">{{ option.code }}</span>
              <span>{{ option.description }}</span>
            </div>
          </template>

          <template #chip="{ value }">
            <div class="inline-flex items-center gap-2 bg-darkgrey text-lightgrey px-3 py-1 rounded-md text-normal">
              <span class="whitespace-nowrap text-normal">{{ value.code }}</span>
              <i
                class="bi bi-x-lg cursor-pointer text-[0.6rem] sm:text-[0.7rem]"
                @click.stop="removeDoctorDiagnosis(value)"
              />
            </div>
          </template>
        </AutoComplete>

        <AutoComplete
          v-else-if="field.type === 'nursing-diagnoses-autocomplete'"
          v-model="answers['nursingDiagnoses.list']"
          :suggestions="filteredNurseDiagnoses"
          multiple
          :minLength="1"
          dropdown
          completeOnFocus
          optionLabel="code"
          @complete="searchNurseDiagnoses"
          class="w-full"
          inputClass="w-full! shadow-none! bg-white! focus:ring-0! focus:shadow-none! border-0! text-normal"
        >
          <template #option="{ option }">
            <div class="flex flex-col">
              <span class="shrink-0 font-medium">{{ option.code }}</span>
              <span>{{ option.description }}</span>
            </div>
          </template>

          <template #chip="{ value }">
            <div class="inline-flex items-center gap-2 bg-darkgrey text-lightgrey px-3 py-1 rounded-md text-normal">
              <span class="whitespace-nowrap text-normal">{{ value.code }}</span>
              <i
                class="bi bi-x-lg cursor-pointer text-[0.6rem] sm:text-[0.7rem]"
                @click.stop="removeNurseDiagnosis(value)"
              />
            </div>
          </template>
        </AutoComplete>


        <!-- radio -->
        <div v-else-if="field.type === 'radio'" class="flex flex-col gap-2">
          <label v-for="opt in field.options" :key="opt.value" class="flex items-center gap-2 cursor-pointer text-normal">
            <input
              type="radio"
              class="accent-accent"
              :name="field.id"
              :value="opt.value"
              :checked="getValue(field.id) === opt.value"
              @change="setValue(field.id, opt.value)"
            />
            <span>{{ opt.label }}</span>
          </label>
        </div>

        <!-- checkbox-group -->
        <div v-else-if="field.type === 'checkbox-group'" class="flex flex-col gap-2">
          <label v-for="opt in field.options" :key="opt.value" class="flex items-center gap-2 cursor-pointer text-normal">
            <input
              type="checkbox"
              class="accent-accent"
              :checked="(getValue(field.id) ?? []).includes(opt.value)"
              @change="toggleInArray(field.id, opt.value, ($event.target as HTMLInputElement).checked)"
            />
            <span>{{ opt.label }}</span>
          </label>
        </div>

        <p v-if="field.help" class="text-sm opacity-70">
          {{ field.help }}
        </p>
      </div>
    </section>

    <div class="sticky bottom-0 z-20 flex justify-end">
      <div class="flex items-center gap-2">
        <Button @click="saveRecord"
          class="bg-accent! border-0! hover:bg-darkgrey! px-4! rounded-md! text-white! text-normal! h-7!">
          Generovať dokument
        </Button>
      </div>
    </div>

  </div>
</template>

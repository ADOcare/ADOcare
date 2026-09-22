export interface VertexTrainingRunExample {
  // columns
  id: number
  training_run_id: number
  feedback_id: number
  dataset_role: string
  created_at: string | null
  // relations
  run: VertexTrainingRun
  // counts
  // exists
  run_exists: boolean
}

export interface Document {
  // columns
  id: number
  patient_id: number | null
  user_id: number
  type: string
  mime_type: string
  name: string
  path: string
  created_at: string | null
  updated_at: string | null
  branch_id: number | null
  period: string | null
  subtype: string | null
  insurance_company_id: number | null
  deleted_at: string | null
  // relations
  patient: Patient
  user: User
  branch: Branch
  insurance_company: InsuranceCompany
  // counts
  // exists
  patient_exists: boolean
  user_exists: boolean
  branch_exists: boolean
  insurance_company_exists: boolean
}

export interface VisitText {
  // columns
  visit_id: number
  text_id: number
  // relations
  visit: Visit
  text: TextBlock
  // counts
  // exists
  visit_exists: boolean
  text_exists: boolean
}

export interface DekurzAiFeedback {
  // columns
  id: number
  document_id: number
  patient_id: number
  user_id: number
  branch_id: number | null
  proposal_document_id: number | null
  source: string
  suggested_sections: Array<unknown>
  final_sections: Array<unknown>
  has_user_edits: boolean
  suggestion_char_count: number
  final_char_count: number
  created_at: string | null
  updated_at: string | null
}

export interface CarDocument {
  // columns
  id: number
  car_id: number
  mime_type: string | null
  path: string
  notes: string | null
  created_at: string | null
  updated_at: string | null
  // relations
  car: Car
  // counts
  // exists
  car_exists: boolean
}

export interface NurseDiagnosis {
  // columns
  id: number
  code: string | null
  description: string | null
  created_at: string | null
  updated_at: string | null
}

export interface Diagnosis {
  // columns
  id: number
  code: string | null
  description: string | null
  created_at: string | null
  updated_at: string | null
}

export interface CompanySubscriptionPayment {
  // columns
  id: number
  company_id: number
  received_at: string
  amount: number
  notes: string | null
  created_at: string | null
  updated_at: string | null
  // relations
  company: Company
  // counts
  // exists
  company_exists: boolean
}

export interface Country {
  // columns
  id: number
  name: string
  code: string
  created_at: string | null
  updated_at: string | null
}

export interface City {
  // columns
  id: number
  name: string
  zip: string | null
  created_at: string | null
  updated_at: string | null
}

export interface PatientCoverage {
  // columns
  id: number
  patient_id: number
  insurance_company_id: number | null
  regime: PatientCoverageRegime
  member_state_code: string | null
  foreign_insured_id: string | null
  special_category: PatientSpecialCoverageCategory | null
  entitlement_document_type: string | null
  entitlement_document_number: string | null
  valid_from: string | null
  valid_to: string | null
  is_verified: boolean
  created_at: string | null
  updated_at: string | null
  // relations
  patient: Patient
  insurance_company: InsuranceCompany
  // counts
  // exists
  patient_exists: boolean
  insurance_company_exists: boolean
}

export interface InsuranceCompany {
  // columns
  id: number
  name: string | null
  address: string | null
  city: string | null
  psc: string | null
  ico: string | null
  dic: string | null
  ic_dph: string | null
  register: string | null
  code: string | null
  branch_code: string | null
  created_at: string | null
  updated_at: string | null
  // relations
  patients: Patient[]
  branches: Branch[]
  // counts
  patients_count: number
  branches_count: number
  // exists
  patients_exists: boolean
  branches_exists: boolean
}

export interface TextBlock {
  // columns
  id: number
  text: string | null
  position: number | null
  created_at: string | null
  updated_at: string | null
  // relations
  visits: Visit[]
  // counts
  visits_count: number
  // exists
  visits_exists: boolean
}

export interface Doctor {
  // columns
  id: number
  first_name: string | null
  last_name: string | null
  title: string | null
  zpr: string | null
  pzs: string | null
  created_at: string | null
  updated_at: string | null
  // relations
  patients: Patient[]
  favourite_in_branches: Branch[]
  assigned_patients: Patient[]
  assigned_branches: Branch[]
  // counts
  patients_count: number
  favourite_in_branches_count: number
  assigned_patients_count: number
  assigned_branches_count: number
  // exists
  patients_exists: boolean
  favourite_in_branches_exists: boolean
  assigned_patients_exists: boolean
  assigned_branches_exists: boolean
}

export interface User {
  // columns
  id: number
  email: string
  pin?: string
  login: string | null
  first_name: string
  last_name: string
  initials: string | null
  title: string | null
  code: string | null
  phone_number: string | null
  remember_token?: string | null
  created_at: string | null
  updated_at: string | null
  api_token?: string | null
  company_id: number | null
  role_id: number | null
  signature_path: string | null
  deleted_at: string | null
  // overrides
  branch_roles: Array<{ branch_id: int, role_id: ?int, position: ?string }>
  // relations
  cars: Car[]
  branches: Branch[]
  role: Role
  company: Company
  report_months: ReportMonth[]
  patients: Patient[]
  represented_companies: Company[]
  tokens: PersonalAccessToken[]
  notifications: DatabaseNotification[]
  // counts
  cars_count: number
  branches_count: number
  report_months_count: number
  patients_count: number
  represented_companies_count: number
  tokens_count: number
  notifications_count: number
  // exists
  cars_exists: boolean
  branches_exists: boolean
  role_exists: boolean
  company_exists: boolean
  report_months_exists: boolean
  patients_exists: boolean
  represented_companies_exists: boolean
  tokens_exists: boolean
  notifications_exists: boolean
}

export interface Role {
  // columns
  id: number
  position: string | null
  created_at: string | null
  updated_at: string | null
  scope: RoleScope
  // mutators
  name: string
  // relations
  users: User[]
  // counts
  users_count: number
  // exists
  users_exists: boolean
}

export interface ScanSession {
  // columns
  id: number
  patient_id: number
  branch_id: number
  user_id: number
  session_token: string
  expires_at: string
  status: string
  document_id: number | null
  created_at: string | null
  updated_at: string | null
  // relations
  patient: Patient
  branch: Branch
  user: User
  document: Document
  // counts
  // exists
  patient_exists: boolean
  branch_exists: boolean
  user_exists: boolean
  document_exists: boolean
}

export interface VertexTrainingRun {
  // columns
  id: number
  pipeline: string
  version: string | null
  status: string
  idempotency_key: string | null
  base_model_name: string | null
  previous_model_name: string | null
  previous_endpoint_name: string | null
  previous_endpoint_id: string | null
  previous_location: string | null
  training_dataset_uri: string | null
  validation_dataset_uri: string | null
  dataset_hash: string | null
  training_examples_count: number
  validation_examples_count: number
  tuning_job_name: string | null
  new_model_name: string | null
  new_endpoint_name: string | null
  new_endpoint_id: string | null
  new_location: string | null
  current_score: number | null
  candidate_score: number | null
  json_validity_rate: number | null
  required_fields_rate: number | null
  critical_errors: number
  http_failures: number
  average_latency_ms: number | null
  started_at: string | null
  completed_at: string | null
  promoted_at: string | null
  failed_at: string | null
  failure_stage: string | null
  failure_message: string | null
  metadata: Array<unknown> | null
  created_at: string | null
  updated_at: string | null
  // relations
  examples: VertexTrainingRunExample[]
  // counts
  examples_count: number
  // exists
  examples_exists: boolean
}

export interface CarService {
  // columns
  id: number
  car_id: number
  name: string
  date: string | null
  interval_days: number
  active: boolean
  created_at: string | null
  updated_at: string | null
  // relations
  car: Car
  // counts
  // exists
  car_exists: boolean
}

export interface SubscriptionTier {
  // columns
  id: number
  name: string
  price_monthly: number | null
  users_limit: number | null
  description: string | null
  is_active: boolean
  sort_order: number
  created_at: string | null
  updated_at: string | null
  deleted_at: string | null
  // relations
  companies: Company[]
  // counts
  companies_count: number
  // exists
  companies_exists: boolean
}

export interface Company {
  // columns
  id: number
  name: string | null
  ico: string | null
  dic: string | null
  ic_dph: string | null
  iban: string | null
  bic: string | null
  register: string | null
  address: string | null
  city: string | null
  psc: string | null
  phone: string | null
  email: string | null
  latitude: number | null
  longitude: number | null
  created_at: string | null
  updated_at: string | null
  representative_id: number | null
  stamp_path: string | null
  invoice_number: number
  send_notifications: boolean
  notification_settings: Array<unknown> | null
  visit_locations: Array<unknown> | null
  deleted_at: string | null
  subscription_tier_id: number | null
  subscription_price_monthly: number | null
  subscription_users_limit_override: number | null
  subscription_status: string | null
  subscription_started_at: string | null
  subscription_ends_at: string | null
  subscription_notes: string | null
  studiokristian_customer_token?: string | null
  status: string | null
  selected_plan_price_id: number | null
  // mutators
  billing_provisioned: boolean
  // relations
  subscription_tier: SubscriptionTier
  branches: Branch[]
  cars: Car[]
  patients: Patient[]
  users: User[]
  representative: User
  subscription_payments: CompanySubscriptionPayment[]
  subscription_paid_months: CompanySubscriptionPaidMonth[]
  // counts
  branches_count: number
  cars_count: number
  patients_count: number
  users_count: number
  subscription_payments_count: number
  subscription_paid_months_count: number
  // exists
  subscription_tier_exists: boolean
  branches_exists: boolean
  cars_exists: boolean
  patients_exists: boolean
  users_exists: boolean
  representative_exists: boolean
  subscription_payments_exists: boolean
  subscription_paid_months_exists: boolean
}

export interface CompanySubscriptionPaidMonth {
  // columns
  id: number
  company_id: number
  year: number
  month: number
  created_at: string | null
  updated_at: string | null
  // relations
  company: Company
  // counts
  // exists
  company_exists: boolean
}

export interface Branch {
  // columns
  id: number
  company_id: number | null
  code: string | null
  identificator: string | null
  address: string | null
  city: string | null
  psc: string | null
  phone: string | null
  email: string | null
  latitude: number | null
  longitude: number | null
  created_at: string | null
  updated_at: string | null
  representative_id: number | null
  terrain_start_time: string | null
  administrative_start_time: string | null
  per_location_time: number | null
  // relations
  company: Company
  representative: User
  users: User[]
  report_months: ReportMonth[]
  cars: Car[]
  patients: Patient[]
  favourite_doctors: Doctor[]
  // counts
  users_count: number
  report_months_count: number
  cars_count: number
  patients_count: number
  favourite_doctors_count: number
  // exists
  company_exists: boolean
  representative_exists: boolean
  users_exists: boolean
  report_months_exists: boolean
  cars_exists: boolean
  patients_exists: boolean
  favourite_doctors_exists: boolean
}

export interface Total {
  // columns
  id: number
  user_id: number
  month: string
  insurance_company_id: number
  points_total: number
  kilometers_total: number
  created_at: string | null
  updated_at: string | null
  branch_id: number | null
  // relations
  user: User
  insurance_company: InsuranceCompany
  branch: Branch
  // counts
  // exists
  user_exists: boolean
  insurance_company_exists: boolean
  branch_exists: boolean
}

export interface Procedure {
  // columns
  id: number
  code: string | null
  description: string | null
  created_at: string | null
  updated_at: string | null
  // relations
  insurance_companies_prices: InsuranceCompany[]
  insurance_companies_prices_minimal: InsuranceCompany[]
  // counts
  insurance_companies_prices_count: number
  insurance_companies_prices_minimal_count: number
  // exists
  insurance_companies_prices_exists: boolean
  insurance_companies_prices_minimal_exists: boolean
}

export interface Invoice {
  // columns
  id: number
  user_id: number
  name: string
  path: string
  insurance_company_id: number | null
  period: string
  total: number
  invoice_number: string | null
  mime_type: string | null
  created_at: string | null
  updated_at: string | null
  type: string
  related_invoice_id: number | null
  deleted_at: string | null
  // relations
  user: User
  insurance_company: InsuranceCompany
  related_invoice: Invoice
  // counts
  // exists
  user_exists: boolean
  insurance_company_exists: boolean
  related_invoice_exists: boolean
}

export interface PatientPoint {
  // columns
  id: number
  date: string | null
  patient_personal_number: string | null
  patient_name: string | null
  patient_id: number | null
  diagnosis_code: string | null
  diagnosis_id: number | null
  procedure_code: string | null
  procedure_id: number | null
  doctor_pzs: string | null
  doctor_zpr: string | null
  doctor_id: number | null
  reference_date: string | null
  user_id: number | null
  branch_id: number | null
  created_at: string | null
  updated_at: string | null
  quantity: number | null
  // relations
  patient: Patient
  // counts
  // exists
  patient_exists: boolean
}

export interface Car {
  // columns
  id: number
  evc: string | null
  company_id: number | null
  user_id: number | null
  created_at: string | null
  updated_at: string | null
  model: string | null
  fuel_consumption_l_per_100km: number | null
  owner_name: string | null
  // relations
  company: Company
  user: User
  documents: CarDocument[]
  services: CarService[]
  // counts
  documents_count: number
  services_count: number
  // exists
  company_exists: boolean
  user_exists: boolean
  documents_exists: boolean
  services_exists: boolean
}

export interface Visit {
  // columns
  id: number
  date: string | null
  patient_id: number | null
  created_at: string | null
  updated_at: string | null
  user_id: number | null
  branch_id: number | null
  terrain_time: string | null
  administrative_time: string | null
  time_on_location: number | null
  distance_to_location: number | null
  time_to_location: number | null
  // relations
  patient: Patient
  user: User
  branch: Branch
  // counts
  // exists
  patient_exists: boolean
  user_exists: boolean
  branch_exists: boolean
}

export interface ReportMonth {
  // columns
  id: number
  month: number | null
  year: number | null
  examination_start: string | null
  examination_end: string | null
  statement_start: string | null
  statement_end: string | null
  first_day: string | null
  last_day: string | null
  user_id: number | null
  branch_id: number | null
  created_at: string | null
  updated_at: string | null
  // relations
  user: User
  branch: Branch
  visits: Visit[]
  // counts
  visits_count: number
  // exists
  user_exists: boolean
  branch_exists: boolean
  visits_exists: boolean
}

export interface Patient {
  // columns
  id: number
  first_name: string | null
  last_name: string | null
  title: string | null
  personal_number: string | null
  sex: string | null
  contact: string | null
  doctor_id: number | null
  insurance_company_id: number | null
  address: string | null
  city: string | null
  zip: string | null
  latitude: number | null
  longitude: number | null
  created_at: string | null
  updated_at: string | null
  reference_date: string | null
  deleted_at: string | null
  dekurz_number: string | null
  branch_id: number | null
  nurse_id: number | null
  country_id: number | null
  death_date: string | null
  // relations
  nurse: User
  branch: Branch
  doctor: Doctor
  visits: Visit[]
  insurance_company: InsuranceCompany
  coverages: PatientCoverage[]
  current_coverage: PatientCoverage
  // counts
  visits_count: number
  coverages_count: number
  // exists
  nurse_exists: boolean
  branch_exists: boolean
  doctor_exists: boolean
  visits_exists: boolean
  insurance_company_exists: boolean
  coverages_exists: boolean
  current_coverage_exists: boolean
}

export interface Plan {
  // columns
  id: number
  company_id: number
  name: string
  text: string
  sort_order: number
  created_at: string | null
  updated_at: string | null
  deleted_at: string | null
  // relations
  company: Company
  // counts
  // exists
  company_exists: boolean
}

export interface Macro {
  // columns
  id: number
  name: string | null
  text: string | null
  abbreviation: string | null
  user_id: number | null
  created_at: string | null
  updated_at: string | null
  // relations
  user: User
  // counts
  // exists
  user_exists: boolean
}

const PatientCoverageRegime = {
  DOMESTIC: 'domestic',
  EU: 'eu',
  SPECIAL: 'special',
  UNCLASSIFIED: 'unclassified',
} as const;

export type PatientCoverageRegime = typeof PatientCoverageRegime[keyof typeof PatientCoverageRegime]

const PatientSpecialCoverageCategory = {
  HOMELESS: 'homeless',
  NON_EU_FOREIGNER: 'non_eu_foreigner',
  STATUTORY_ENTITLEMENT_9_3: 'statutory_entitlement_9_3',
} as const;

export type PatientSpecialCoverageCategory = typeof PatientSpecialCoverageCategory[keyof typeof PatientSpecialCoverageCategory]

const RoleScope = {
  BRANCH: 'branch',
  COMPANY: 'company',
  GLOBAL: 'global',
} as const;

export type RoleScope = typeof RoleScope[keyof typeof RoleScope]

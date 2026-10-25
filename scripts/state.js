import { SESSION_STORAGE_KEY } from "./constants.js";

const initialState = {
  session: {
    isAuthenticated: false,
    role: null,
    user: null
  },
  clinicInfo: {
    name: "BR Dental Clinic",
    phone: "09066636112",
    address: "73 Nyugan Road, Paliparan 1, Dasmarinas City, Cavite",
    dentists: [
      {
        id: "DR-01",
        name: "Dr. Ma. Elena B. Retuerna",
        title: "Lead Practitioner",
        specialty: "General Dentistry"
      }
    ]
  },
  services: [
    { id: "SRV-101", name: "Comprehensive Checkup", desc: "Complete oral examination and consultation.", price: "Contact the clinic for pricing" },
    { id: "SRV-102", name: "Dental Cleaning (Prophylaxis)", desc: "Removal of plaque and tartar deposits.", price: "Contact the clinic for pricing" },
    { id: "SRV-103", name: "Composite Filling", desc: "Tooth-colored restoration for cavities.", price: "Contact the clinic for pricing" },
    { id: "SRV-104", name: "Tooth Extraction", desc: "Safe removal of compromised teeth.", price: "Contact the clinic for pricing" }
  ],
  patients: [],
  appointments: [],
  dentalRecords: [],
  notifications: [],
  staffUsers: []
};

const savedState = sessionStorage.getItem(SESSION_STORAGE_KEY);
const parsedState = savedState ? JSON.parse(savedState) : {};

export const state = {
  ...initialState,
  ...parsedState,
  session: { ...initialState.session, ...parsedState.session },
  clinicInfo: { ...initialState.clinicInfo, ...parsedState.clinicInfo },
  services: parsedState.services || initialState.services,
  patients: parsedState.patients || initialState.patients,
  appointments: parsedState.appointments || initialState.appointments,
  dentalRecords: parsedState.dentalRecords || initialState.dentalRecords,
  notifications: parsedState.notifications || initialState.notifications,
  staffUsers: parsedState.staffUsers || initialState.staffUsers
};

export function saveState() {
  sessionStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(state));
}

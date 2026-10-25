export const ROLE_DEFINITIONS = [
  {
    key: "super-admin",
    name: "Master Admin",
    permissions: [
      "Manage users and roles",
      "Manage clinic settings",
      "Access and manage all system data",
      "Generate and view reports"
    ]
  },
  {
    key: "patient",
    name: "User",
    permissions: [
      "Register and log in",
      "View dentists and available services",
      "Book appointments",
      "Reschedule or cancel own appointments",
      "View appointment history"
    ]
  }
];

export const CREATABLE_ROLES = {
  "super-admin": ["super-admin"]
};

export const SESSION_STORAGE_KEY = "br-dental-clinic-session";

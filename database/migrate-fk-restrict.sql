-- BR Dental Clinic: clinical-data integrity
-- ============================================================
-- Deleting a patient, dentist, or service must never silently wipe
-- appointments or dental records. Clinical data uses RESTRICT;
-- the admin archives instead of deleting.
-- ============================================================
USE clinic;

-- appointments.*
ALTER TABLE appointments DROP FOREIGN KEY appointments_ibfk_1;
ALTER TABLE appointments
  ADD CONSTRAINT fk_appointments_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE appointments DROP FOREIGN KEY appointments_ibfk_3;
ALTER TABLE appointments
  ADD CONSTRAINT fk_appointments_dentist FOREIGN KEY (dentist_id) REFERENCES dentists(id) ON DELETE RESTRICT;

ALTER TABLE appointments DROP FOREIGN KEY appointments_ibfk_2;
ALTER TABLE appointments
  ADD CONSTRAINT fk_appointments_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT;

-- dental_records.*
ALTER TABLE dental_records DROP FOREIGN KEY dental_records_ibfk_1;
ALTER TABLE dental_records
  ADD CONSTRAINT fk_dental_records_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE RESTRICT;

ALTER TABLE dental_records DROP FOREIGN KEY dental_records_ibfk_2;
ALTER TABLE dental_records
  ADD CONSTRAINT fk_dental_records_dentist FOREIGN KEY (dentist_id) REFERENCES dentists(id) ON DELETE RESTRICT;

-- notifications may cascade (not clinical data)
ALTER TABLE notifications DROP FOREIGN KEY notifications_ibfk_1;

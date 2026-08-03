<?php

namespace Database\Seeders;

use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Default copy for every message template, in every language. One row per
 * (key, language) - the recipient's preference picks which one sends, falling
 * back to English (TemplateRenderer::resolveTemplate).
 *
 * Two entry points, deliberately different:
 *  - run()         resets bodies to these defaults (fresh installs / seeding).
 *  - seedMissing() only inserts rows that don't exist, so a migration can add a
 *                  new language or key without clobbering copy the owner edited.
 *
 * The tech reminder digests (TemplateKey::TechReminder*) have no rows here:
 * their bodies are assembled per-tech in TechReminderScheduler.
 *
 * ⚠ Best practice (2026-07-17): write straight quotes ' " and hyphens -, never
 * curly quotes ' ' " " or em/en-dashes — —. One non-GSM-7 character forces the
 * WHOLE message into UCS-2 encoding, cutting the per-segment limit from 153 to
 * 67 chars — a long template can silently jump from ~5 SMS segments to ~12,
 * which carriers/A2P filters are far more likely to drop even though the
 * provider API reports success. (The Filament template editor
 * (MessageTemplateForm::validateBody) rejects these live; this seeder does
 * not run through that validator, so it's on the author here.) Spanish copy
 * still needs á/é/í/ó/ú/ñ/¡/¿, which is unavoidable — é/ñ/¡/¿ are natively in
 * GSM-7, but á/í/ó/ú are not, so most Spanish bodies are UCS-2 regardless;
 * still avoid the smart punctuation on top of that.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::definitions() as $row) {
            MessageTemplate::updateOrCreate(
                ['key' => $row['key'], 'language' => $row['language']],
                $row + ['channel' => 'sms', 'active' => true],
            );
        }
    }

    /** Add any (key, language) rows that don't exist yet; never touches existing copy. */
    public static function seedMissing(): void
    {
        foreach (self::definitions() as $row) {
            MessageTemplate::firstOrCreate(
                ['key' => $row['key'], 'language' => $row['language']],
                $row + ['channel' => 'sms', 'active' => true],
            );
        }
    }

    /**
     * Flattened (key, language, name, body) rows built from self::bodies().
     *
     * @return array<int, array{key: string, language: string, name: string, body: string}>
     */
    public static function definitions(): array
    {
        $rows = [];

        foreach (self::bodies() as $key => $byLanguage) {
            $name = TemplateKey::from($key)->label();

            foreach ($byLanguage as $language => $body) {
                $rows[] = compact('key', 'language', 'name', 'body');
            }
        }

        return $rows;
    }

    /**
     * Default body per template key, per language.
     *
     * @return array<string, array<string, string>>
     */
    private static function bodies(): array
    {
        $en = Language::English->value;
        $es = Language::Spanish->value;

        // Opt-out language. Carried by the review drip and ONLY the review drip
        // - a customer may opt out of being asked for a review, never out of
        // their own appointment/payment texts (TemplateKey::requiresOptOutLanguage).
        $optOutEn = ' Reply STOP to opt out.';
        $optOutEs = ' Responde STOP para dejar de recibir mensajes.';

        // The completion text, sent when dispatch marks the work complete. One
        // copy per payment method (the "Job Complete - ..." family): Zelle keeps
        // this `job_complete` key, Venmo/Cash/Check are clones the owner rewords,
        // and Credit Card swaps the payment paragraph for the real Stripe link.
        $zellePara = "Reminder the total amount due is {quote}.  Payment can be made by Zelle to  payments@example.com (Account Name: YOUR BUSINESS LLC).\n\n"
            .'If you\'d prefer to pay with debit card or credit card (with a 3% processing fee) instead, just let us know and we\'ll prepare a payment link for you.';

        $jobComplete = "Hi {first_name}, \n\n"
            ."Our technician just confirmed your glass service is completed.  Please let us know if there were any issues.\n\n"
            .'Please note: if our technician left any tape on the glass after installation, please leave it on for 48 hours.  '
            .'The tape helps support the glass while the adhesive cures and allows everything to settle properly. '
            ."Removing or moving it too early can affect the fitment and will void our installation warranty.\n\n"
            .$zellePara."\n\n"
            .'Thank you for choosing {location_name}!';

        // Same text, with both Zelle paragraphs replaced by the card link.
        $jobCompleteCard = str_replace(
            $zellePara,
            'Reminder the total amount due is {payment_total} (includes a 3% card processing fee). You can pay securely by card here: {payment_link}',
            $jobComplete,
        );

        $zelleParaEs = "Recuerda que el total a pagar es {quote}.  El pago se puede hacer por Zelle a  payments@example.com (Nombre de la cuenta: YOUR BUSINESS LLC).\n\n"
            .'Si prefieres pagar con tarjeta de débito o crédito (con un cargo de 3% por procesamiento), solo avísanos y te preparamos un enlace de pago.';

        $jobCompleteEs = "Hola {first_name}, \n\n"
            ."Nuestro técnico acaba de confirmar que tu servicio de vidrio está terminado.  Avísanos si hubo algún problema.\n\n"
            .'Nota importante: si nuestro técnico dejó cinta en el vidrio después de la instalación, por favor déjala puesta por 48 horas.  '
            .'La cinta ayuda a sostener el vidrio mientras el adhesivo cura y permite que todo se asiente correctamente. '
            ."Quitarla o moverla antes de tiempo puede afectar el ajuste y anula nuestra garantía de instalación.\n\n"
            .$zelleParaEs."\n\n"
            .'¡Gracias por elegir {location_name}!';

        $jobCompleteCardEs = str_replace(
            $zelleParaEs,
            'Recuerda que el total a pagar es {payment_total} (incluye un cargo de 3% por procesamiento de tarjeta). Puedes pagar de forma segura con tarjeta aquí: {payment_link}',
            $jobCompleteEs,
        );

        // Completion text for a job the customer already paid before it was
        // marked complete - same wrap-up + tape warning, but the payment
        // paragraph is dropped entirely (nothing is owed).
        $jobCompletePaid = "Hi {first_name}, \n\n"
            ."Our technician just confirmed your glass service is completed.  Please let us know if there were any issues.\n\n"
            .'Please note: if our technician left any tape on the glass after installation, please leave it on for 48 hours.  '
            .'The tape helps support the glass while the adhesive cures and allows everything to settle properly. '
            ."Removing or moving it too early can affect the fitment and will void our installation warranty.\n\n"
            .'Thank you for choosing {location_name}!';

        $jobCompletePaidEs = "Hola {first_name}, \n\n"
            ."Nuestro técnico acaba de confirmar que tu servicio de vidrio está terminado.  Avísanos si hubo algún problema.\n\n"
            .'Nota importante: si nuestro técnico dejó cinta en el vidrio después de la instalación, por favor déjala puesta por 48 horas.  '
            .'La cinta ayuda a sostener el vidrio mientras el adhesivo cura y permite que todo se asiente correctamente. '
            ."Quitarla o moverla antes de tiempo puede afectar el ajuste y anula nuestra garantía de instalación.\n\n"
            .'¡Gracias por elegir {location_name}!';

        return [
            TemplateKey::WelcomeWithTime->value => [
                $en => "Hi {first_name}, thanks for choosing {location_name}! We have your appointment set for {scheduled_date_time}. We'll text you with any updates.",
                $es => 'Hola {first_name}, ¡gracias por elegir {location_name}! Tenemos tu cita programada para {scheduled_date_time}. Te avisaremos por mensaje si hay algún cambio.',
            ],
            TemplateKey::WelcomeGeneric->value => [
                $en => 'Hi {first_name}, thanks for choosing {location_name}! We received your request for {vehicle} glass service (requested time: {preferred_date_time}) and will reach out shortly to schedule.',
                $es => 'Hola {first_name}, ¡gracias por elegir {location_name}! Recibimos tu solicitud de servicio de vidrio para tu {vehicle} (horario solicitado: {preferred_date_time}) y te contactaremos en breve para agendar la cita.',
            ],
            // Dispatch team's intro, sent from our Quo number once we take the
            // job over from the sales team. Used in BOTH welcome flows. The
            // after-hours / weekend / holiday variants below fire instead based
            // on the local time (see TemplateKey::welcomeDispatchIntroFor).
            TemplateKey::WelcomeDispatchIntro->value => [
                $en => "Hi {first_name}, this is the scheduling team at {location_name}. We've got your {vehicle} glass service and your requested time of {preferred_date_time}. We'll text you shortly to lock it in - feel free to reply with any questions! -{dispatch}",
                $es => 'Hola {first_name}, te saluda el equipo de citas de {location_name}. Ya tenemos tu servicio de vidrio para tu {vehicle} y el horario que pediste: {preferred_date_time}. Te escribimos en un momento para confirmarlo - ¡responde aquí si tienes alguna pregunta! -{dispatch}',
            ],
            // After 7pm Mon-Fri.
            TemplateKey::WelcomeDispatchIntroAfterHours->value => [
                $en => "Hi {first_name}, this is the scheduling team at {location_name}. We've got your {vehicle} glass service and your requested time of {preferred_date_time}. We'll reach out first thing in the morning to lock it in. Feel free to reply here anytime - talk soon! -{dispatch}",
                $es => 'Hola {first_name}, te saluda el equipo de citas de {location_name}. Ya tenemos tu servicio de vidrio para tu {vehicle} y el horario que pediste: {preferred_date_time}. Mañana a primera hora te contactamos para confirmarlo. Puedes responder aquí cuando quieras. ¡Hablamos pronto! -{dispatch}',
            ],
            // Saturday after 4pm + anytime Sunday (the weekend variant).
            TemplateKey::WelcomeDispatchIntroSunday->value => [
                $en => "Hi {first_name}, this is the scheduling team at {location_name}. We've got your {vehicle} glass service and your requested time of {preferred_date_time}. We'll be in touch to get you scheduled. Feel free to reply here anytime - talk soon! -{dispatch}",
                $es => 'Hola {first_name}, te saluda el equipo de citas de {location_name}. Ya tenemos tu servicio de vidrio para tu {vehicle} y el horario que pediste: {preferred_date_time}. Te contactaremos para agendar la cita. Puedes responder aquí cuando quieras. ¡Hablamos pronto! -{dispatch}',
            ],
            // After 7pm Mon-Fri when the NEXT day is a holiday.
            TemplateKey::WelcomeDispatchIntroPreHolidayAfterHours->value => [
                $en => "Hi {first_name}, this is the scheduling team at {location_name}. We've got your {vehicle} glass service and your requested time of {preferred_date_time}. We're closed for the holiday tomorrow, so we'll reach out to lock in a time as soon as we're back. Feel free to reply here anytime - talk soon! -{dispatch}",
                $es => 'Hola {first_name}, te saluda el equipo de citas de {location_name}. Ya tenemos tu servicio de vidrio para tu {vehicle} y el horario que pediste: {preferred_date_time}. Mañana estamos cerrados por el feriado, así que te contactaremos para confirmar la hora en cuanto regresemos. Puedes responder aquí cuando quieras. ¡Hablamos pronto! -{dispatch}',
            ],
            // Any time on a holiday.
            TemplateKey::WelcomeDispatchIntroHoliday->value => [
                $en => "Hi {first_name}, this is the scheduling team at {location_name}. We've got your {vehicle} glass service and your requested time of {preferred_date_time}. We're closed for the holiday today but will be in touch to get you scheduled as soon as we're back. Feel free to reply here anytime - talk soon! -{dispatch}",
                $es => 'Hola {first_name}, te saluda el equipo de citas de {location_name}. Ya tenemos tu servicio de vidrio para tu {vehicle} y el horario que pediste: {preferred_date_time}. Hoy estamos cerrados por el feriado, pero te contactaremos para agendar la cita en cuanto regresemos. Puedes responder aquí cuando quieras. ¡Hablamos pronto! -{dispatch}',
            ],
            // Sent FROM the Podium sales thread when sales hasn't yet told the
            // customer about the handoff. Explains that a new number (our Quo
            // line) will be reaching out, so the follow-up isn't a surprise.
            TemplateKey::WelcomePodiumHandoff->value => [
                $en => 'Hi {first_name}, thanks again! Our scheduling team at {location_name} will take it from here and text you shortly from a new number to set up your appointment.',
                $es => 'Hola {first_name}, ¡gracias de nuevo! Nuestro equipo de citas de {location_name} continuará desde aquí y te escribirá en breve desde un número nuevo para coordinar tu cita.',
            ],
            // The scheduling ask — one text sent by hand right after the welcome
            // (JobActions::sendSchedulingAsk). Which of the five goes out is
            // decided by App\Support\SchedulingAsk from the requested day + time
            // preference. The two *Future bodies exist to pull a far-out
            // appointment earlier: the longer the wait, the more of them cancel,
            // so they name concrete near days ({earlier_window}) and make saying
            // yes easy rather than pushing back on the customer's date.
            TemplateKey::SchedulingAskNoDate->value => [
                $en => 'Hi {first_name} - do you have a time preference for {earliest_day}, or should I put you in the earliest appointment we have open?',
                $es => 'Hola {first_name} - ¿tienes alguna preferencia de horario para {earliest_day}, o te agendo en la primera cita que tengamos disponible?',
            ],
            TemplateKey::SchedulingAskNextDay->value => [
                $en => 'Hi {first_name} - we have you down for {requested_day}. Do you have a time preference that day, or would you like our earliest opening?',
                $es => 'Hola {first_name} - te tenemos anotado para {requested_day}. ¿Tienes alguna preferencia de horario ese día, o prefieres nuestra primera cita disponible?',
            ],
            TemplateKey::SchedulingAskFuture->value => [
                $en => 'Hi {first_name} - we have you down for {requested_day}. Do you have a time preference that day, or would you like our earliest opening? We also have availability {earlier_window} if you would rather get it done sooner - just say the word.',
                $es => 'Hola {first_name} - te tenemos anotado para {requested_day}. ¿Tienes alguna preferencia de horario ese día, o prefieres nuestra primera cita disponible? También tenemos disponibilidad {earlier_window} si prefieres resolverlo antes - solo avísanos.',
            ],
            TemplateKey::SchedulingConfirmNextDay->value => [
                $en => 'Hi {first_name} - got it: {requested_day} {requested_time}. I will follow up shortly with your exact arrival window.',
                $es => 'Hola {first_name} - listo: {requested_day} {requested_time}. En un momento te confirmo tu ventana exacta de llegada.',
            ],
            TemplateKey::SchedulingConfirmFuture->value => [
                $en => 'Hi {first_name} - got it: {requested_day} {requested_time}. I will follow up shortly with your exact arrival window. If you would like it done sooner, I can also fit you in {earlier_window} - happy to move it up.',
                $es => 'Hola {first_name} - listo: {requested_day} {requested_time}. En un momento te confirmo tu ventana exacta de llegada. Si prefieres resolverlo antes, también te puedo agendar {earlier_window} - con gusto lo adelantamos.',
            ],
            TemplateKey::TimeConfirmation->value => [
                $en => 'Hi {first_name}, your {vehicle} glass service with {location_name} is confirmed for {scheduled_date_time} with your technician {technician}. Your total is {quote}. Payment is due at the time of service - we accept Zelle, Venmo, card, or cash; reply here for payment details. Reply here if you need to make any changes.',
                $es => 'Hola {first_name}, tu servicio de vidrio para tu {vehicle} con {location_name} está confirmado para {scheduled_date_time} con tu técnico {technician}. Tu total es {quote}. El pago se hace el día del servicio - aceptamos Zelle, Venmo, tarjeta o efectivo; responde aquí para los datos de pago. Responde aquí si necesitas hacer algún cambio.',
            ],
            // Same confirmation as TimeConfirmation, plus the two-visit calibration note.
            TemplateKey::TimeConfirmationCalibration->value => [
                $en => 'Hi {first_name}, your {vehicle} glass service with {location_name} is confirmed for {scheduled_date_time} with your technician {technician}. Your total is {quote}. Payment is due at the time of service - we accept Zelle, Venmo, card, or cash; reply here for payment details. Please note: ADAS calibration is a separate visit scheduled right after your windshield is installed - for best results we always book these as two appointments. Our calibration partner will reach out to schedule that visit once the glass is in. Reply here if you need to make any changes.',
                $es => 'Hola {first_name}, tu servicio de vidrio para tu {vehicle} con {location_name} está confirmado para {scheduled_date_time} con tu técnico {technician}. Tu total es {quote}. El pago se hace el día del servicio - aceptamos Zelle, Venmo, tarjeta o efectivo; responde aquí para los datos de pago. Nota: la calibración ADAS es una visita separada que se agenda justo después de instalar el parabrisas - por mejores resultados siempre las programamos en dos citas. Nuestro socio de calibración te contactará para agendar esa visita una vez instalado el vidrio. Responde aquí si necesitas hacer algún cambio.',
            ],
            TemplateKey::AppointmentRescheduled->value => [
                $en => "Hi {first_name}, your {vehicle} glass service with {location_name} has been rescheduled to {scheduled_date_time}. Reply here if that doesn't work or you have any questions.",
                $es => 'Hola {first_name}, tu servicio de vidrio para tu {vehicle} con {location_name} se reprogramó para {scheduled_date_time}. Responde aquí si no te funciona o si tienes alguna pregunta.',
            ],
            // "Cancel appointment" - the customer needs to reschedule but has no
            // new time yet. No {scheduled_date_time} on purpose (there isn't one).
            TemplateKey::AppointmentCanceledPendingReschedule->value => [
                $en => "Hi {first_name}, your {vehicle} glass service with {location_name} has been canceled for now. We'll follow up shortly to get a new date and time that works for you. Reply here with any questions!",
                $es => 'Hola {first_name}, tu servicio de vidrio para tu {vehicle} con {location_name} quedó cancelado por ahora. Te contactaremos en breve para acordar una nueva fecha y hora que te funcione. ¡Responde aquí si tienes alguna pregunta!',
            ],
            // Heads-up to the assigned TECHNICIAN that a job came off their
            // schedule, in the tech's own language (Employee.preferred_language).
            TemplateKey::TechJobCanceled->value => [
                $en => "A job was canceled off your schedule:\n\n• City: {city}\n• Vehicle: {vehicle}\n• Job: {job_type}\n\nThe customer will reschedule later. No need to go for now.",
                $es => "Se canceló un trabajo de tu horario:\n\n• Ciudad: {city}\n• Vehículo: {vehicle}\n• Trabajo: {job_type}\n\nEl cliente va a reagendar más adelante. Ya no necesitas ir por ahora.",
            ],
            // Reminder to the TECH to return a part they'd already picked up when
            // the appointment gets canceled, in the tech's own language.
            TemplateKey::TechReturnPart->value => [
                $en => "The job you picked up a part for was canceled:\n\n• City: {city}\n• Vehicle: {vehicle}\n• Part: {part_number}\n• Job: {job_type}\n\nPlease return the part when you get a chance. Thank you!",
                $es => "El trabajo para el que recogiste una pieza se canceló:\n\n• Ciudad: {city}\n• Vehículo: {vehicle}\n• Parte: {part_number}\n• Trabajo: {job_type}\n\nPor favor devuelve la pieza cuando puedas. ¡Gracias!",
            ],
            TemplateKey::ReminderNightBefore->value => [
                $en => 'Reminder from {location_name}: your glass service is scheduled for {scheduled_date_time}. See you then!',
                $es => 'Recordatorio de {location_name}: tu servicio de vidrio está programado para {scheduled_date_time}. ¡Nos vemos!',
            ],
            TemplateKey::ReminderMorningOf->value => [
                $en => 'Good morning {first_name}! Your glass service with {location_name} is today at {scheduled_date_time}.',
                $es => '¡Buenos días {first_name}! Tu servicio de vidrio con {location_name} es hoy, {scheduled_date_time}.',
            ],
            TemplateKey::JobComplete->value => [
                $en => $jobComplete,
                $es => $jobCompleteEs,
            ],
            TemplateKey::JobCompleteCreditCard->value => [
                $en => $jobCompleteCard,
                $es => $jobCompleteCardEs,
            ],
            // Venmo/Cash/Check ship as verbatim clones of the Zelle copy; the
            // owner rewords each one's payment paragraph in Message Templates.
            TemplateKey::JobCompleteVenmo->value => [
                $en => $jobComplete,
                $es => $jobCompleteEs,
            ],
            TemplateKey::JobCompleteCash->value => [
                $en => $jobComplete,
                $es => $jobCompleteEs,
            ],
            TemplateKey::JobCompleteCheck->value => [
                $en => $jobComplete,
                $es => $jobCompleteEs,
            ],
            // Already-paid completion - no payment paragraph.
            TemplateKey::JobCompletePaid->value => [
                $en => $jobCompletePaid,
                $es => $jobCompletePaidEs,
            ],
            // On-demand card payment link ({payment_link}/{payment_total} are set
            // on the job right before the text is queued - see JobLifecycle).
            // Sent by the standalone "Text card payment link" action only.
            TemplateKey::PaymentLink->value => [
                $en => "Hi {first_name}, here's a secure link to pay for your {vehicle} glass service with {location_name} by card: {payment_link} Total: {payment_total} (includes a 3% card processing fee). Reply here with any questions!",
                $es => 'Hola {first_name}, aquí tienes un enlace seguro para pagar con tarjeta tu servicio de vidrio para tu {vehicle} con {location_name}: {payment_link} Total: {payment_total} (incluye un cargo de 3% por procesamiento de tarjeta). ¡Responde aquí si tienes alguna pregunta!',
            ],
            // One generic heads-up when a warranty return visit is scheduled
            // ({warranty_date_time} comes from the warranty claim's appointment).
            // Multi-day install: the first visit is done and the second one now
            // has a date. Deliberately generic about WHY it takes two days.
            TemplateKey::MultiDayNextVisit->value => [
                $en => 'Hi {first_name}, this is {location_name}. The next part of your service is scheduled for {scheduled_date_time}. Reply here with any questions - see you then!',
                $es => 'Hola {first_name}, te saluda {location_name}. La siguiente parte de tu servicio está programada para {scheduled_date_time}. Responde aquí si tienes alguna pregunta - ¡nos vemos!',
            ],
            TemplateKey::WarrantyVisitScheduled->value => [
                $en => "Hi {first_name}, this is {location_name}. We'll be there on {warranty_date_time} to take a look at the issue you mentioned. Reply here with any questions - see you then!",
                $es => 'Hola {first_name}, te saluda {location_name}. Estaremos ahí el {warranty_date_time} para revisar el problema que nos comentaste. Responde aquí si tienes alguna pregunta - ¡nos vemos!',
            ],
            // Warranty return-visit reminders (customer) — night before / morning of.
            TemplateKey::WarrantyReminderNightBefore->value => [
                $en => "Hi {first_name}, a reminder from {location_name}: we'll be by {warranty_date_time} to take another look at the issue you mentioned on your {vehicle}. Reply here with any questions - see you then!",
                $es => 'Hola {first_name}, un recordatorio de {location_name}: pasaremos {warranty_date_time} para revisar de nuevo el problema que nos comentaste con tu {vehicle}. Responde aquí si tienes alguna pregunta - ¡nos vemos!',
            ],
            TemplateKey::WarrantyReminderMorningOf->value => [
                $en => "Hi {first_name}, this is {location_name} - just confirming we'll be by {warranty_date_time} today to take another look at the issue on your {vehicle}. Reply here with any questions!",
                $es => 'Hola {first_name}, te saluda {location_name} - confirmamos que pasaremos hoy {warranty_date_time} para revisar de nuevo el problema con tu {vehicle}. ¡Responde aquí si tienes alguna pregunta!',
            ],
            // Warranty return-visit reminders (technician) — greet the tech, name
            // the customer + vehicle so they know whose fix they're heading to.
            TemplateKey::TechWarrantyReminderNightBefore->value => [
                $en => 'Hi {technician}, reminder: you have a warranty return visit {warranty_date_time} for {first_name} ({vehicle}). Please take another look at the reported issue.',
                $es => 'Hola {technician}, recordatorio: tienes una visita de garantía {warranty_date_time} para {first_name} ({vehicle}). Por favor revisa de nuevo el problema reportado.',
            ],
            TemplateKey::TechWarrantyReminderMorningOf->value => [
                $en => 'Hi {technician}, reminder: warranty return visit today {warranty_date_time} for {first_name} ({vehicle}). Please take another look at the reported issue.',
                $es => 'Hola {technician}, recordatorio: visita de garantía hoy {warranty_date_time} para {first_name} ({vehicle}). Por favor revisa de nuevo el problema reportado.',
            ],
            // Warranty visit wrapped up — plain "all set", NO review drip follows.
            TemplateKey::WarrantyCompleted->value => [
                $en => "Hi {first_name}, this is {location_name} - all set! We've taken care of the issue you mentioned on your {vehicle}. Thanks for your patience, and reply here if anything else comes up.",
                $es => 'Hola {first_name}, te saluda {location_name} - ¡listo! Ya resolvimos el problema que nos comentaste con tu {vehicle}. Gracias por tu paciencia, y responde aquí si surge algo más.',
            ],
            // On-request receipt, sent from a paid job's "Send receipt" action.
            // {receipt_link} is a fresh 30-day signed link to the server-built
            // PDF (App\Services\Receipts\ReceiptPdfService) - passed as an
            // extra var at send time, since it needs a signature minted right
            // then and can't be derived from the job alone.
            // ({amount_paid}/{payment_method}/{payment_date} still resolve
            // from the recorded payment but are no longer used in this body.)
            // Spanish body is deliberately accent-free (unlike most Spanish
            // copy here) so it stays GSM-7 alongside the long signed link -
            // every extra UCS-2 segment is more surface for a carrier to drop
            // (see "ASCII-only punctuation" in CLAUDE.md).
            TemplateKey::Receipt->value => [
                $en => "Hi {first_name}, thanks for your business! Here's your receipt from {location_name}: {receipt_link}",
                $es => 'Hola {first_name}, gracias por tu preferencia! Aqui esta tu recibo de {location_name}: {receipt_link}',
            ],
            // Outreach to TECHNICIANS (not the customer): after-hours "can you
            // take this job?" with city only - no address/name/phone. Most of the
            // crew reads the Spanish row.
            TemplateKey::TechJobAvailability->value => [
                $en => "Hi, I have a new job.\n\n• City: {city}\n• Preferred time: {preferred_date_time}\n• Vehicle: {vehicle}\n• Part: {part_number}\n• Job: {job_type}\n\nCan you take it? What time could you get there? And if so, which shop should we order the part from?",
                $es => "Hola, tengo un trabajo nuevo.\n\n• Ciudad: {city}\n• Preferencia de horario: {preferred_date_time}\n• Vehículo: {vehicle}\n• Parte: {part_number}\n• Trabajo: {job_type}\n\n¿Lo puedes hacer? ¿A qué hora podrías atenderlo? Y si sí, ¿a qué tienda quieres que pidamos la pieza?",
            ],
            // The card the ASSIGNED tech gets the moment a job is booked. The
            // opposite trade-off to TechJobAvailability above: that one is sent
            // to techs who may not take the job, so it withholds the customer's
            // name, phone and street; this one goes to the tech who owns it, so
            // it carries everything needed to run the job without opening the
            // dashboard. {warehouse} falls back to "Waiting on you to select
            // warehouse" when no parts source has been picked yet
            // (TemplateRenderer::warehousePendingPhrase).
            TemplateKey::TechJobConfirmation->value => [
                $en => "Confirmed - this job is yours:\n\nName: {first_name}\nPhone: {customer_phone}\nAddress: {service_address}\nTime: {scheduled_date_time}\nVehicle: {vehicle}\nPart #: {part_number}\nWarehouse: {warehouse}",
                $es => "Confirmado - este trabajo es tuyo:\n\nNombre: {first_name}\nTelefono: {customer_phone}\nDireccion: {service_address}\nHora: {scheduled_date_time}\nVehiculo: {vehicle}\nParte #: {part_number}\nAlmacen: {warehouse}",
            ],
            // "Schedule w/ AI" negotiation family (docs/ai-scheduling-spec.md).
            // Unlike TechJobAvailability above, these propose a SPECIFIC slot
            // rather than asking an open question, so the reply can be
            // classified as accept/counter/decline instead of free text.
            TemplateKey::TechAiSlotOffer->value => [
                $en => "Hi, I have a job for {slot_phrase}.\n\nVehicle: {vehicle}\nJob: {job_type}\nCity: {city}\n\n{load_note}Can you take it? If not, no problem, just let me know.",
                $es => "Hola, tengo un trabajo para {slot_phrase}.\n\nVehículo: {vehicle}\nTrabajo: {job_type}\nCiudad: {city}\n\n{load_note}¿Lo puedes hacer? Si no puedes, no hay problema, solo avísame.",
            ],
            // Pushback when a tech's counter-offer doesn't fit what the
            // customer can actually do - same tech, re-stating the requirement.
            TemplateKey::TechAiSlotReiterate->value => [
                $en => "Unfortunately, the customer needs it {constraint_phrase}. Can you make it work? If not, no worries, just let me know and I'll check with someone else.",
                $es => 'El cliente lo necesita {constraint_phrase}. ¿Lo puedes hacer? Si no puedes, no hay problema, avísame y le pregunto a alguien más.',
            ],
            // The tech agreed to a whole part of day, so we pick the earliest
            // 2-hour slot in it and tell them what we're about to book. They
            // get a short window to come back with a different time that still
            // works for the customer; silence books what we proposed.
            TemplateKey::TechAiSlotConfirm->value => [
                $en => 'Great - we will book it for {slot_phrase}. Confirming that works. If you need a different time, just let me know.',
                $es => 'Perfecto - lo vamos a agendar para {slot_phrase}. Confirmame que te funciona. Si necesitas otra hora, solo avisame.',
            ],
            // For a tech who answered by TAPPING a reaction rather than
            // writing. It leads with what we understood ("sounds like you are
            // saying yes") because a thumbs up can also just mean "got it" -
            // then names the slot and asks, so a misread costs one text
            // instead of a wrongly booked job.
            TemplateKey::TechAiReactionConfirm->value => [
                $en => 'It sounds like you are saying yes to this job. We will book it for {slot_phrase}. Does that work for you?',
                $es => 'Parece que me dices que si a este trabajo. Lo vamos a agendar para {slot_phrase}. ¿Te funciona?',
            ],
            // (`tech_ai_assigned` — "You're all set, this job is yours for
            // {slot_phrase}" — was retired 2026-07-30. A booked AI job now
            // closes on the full TechJobConfirmation card above, which carries
            // the slot plus everything else the tech needs.)
            //
            // Asked right after the booking, only when the job has no parts
            // source yet. Deliberately open-ended rather than a numbered menu -
            // ten warehouse names would be a wall of text, and the crew names
            // their shop the same way every time.
            TemplateKey::TechAiWarehouseAsk->value => [
                $en => 'One more thing - which warehouse should we order the part from?',
                $es => 'Una cosa mas - ¿de que almacen quieres que pidamos la pieza?',
            ],
            TemplateKey::TechAiWarehouseSet->value => [
                $en => 'Got it - we will order the part from {warehouse}. Thank you!',
                $es => 'Perfecto - vamos a pedir la pieza de {warehouse}. Gracias!',
            ],
            // Sent to a tech who said yes after the job already went to someone
            // else (a race, or they were superseded by a customer retarget).
            TemplateKey::TechAiFilled->value => [
                $en => "Thanks for getting back to me, this one already went to another tech. I'll reach out next time something opens up.",
                $es => 'Gracias por responder, este trabajo ya se lo dieron a otro técnico. Te aviso la próxima vez que haya algo disponible.',
            ],
            // CUSTOMER-facing: sent only when a live AI scheduling session
            // retargets onto a new day/time because the customer texted back
            // asking for something different.
            TemplateKey::CustomerAiTimeCheck->value => [
                $en => 'Hi {first_name}, can we do {slot_phrase} instead? Reply YES to confirm, or let us know what works better.',
                $es => 'Hola {first_name}, ¿podemos hacerlo {slot_phrase} en su lugar? Responde SI para confirmar, o dinos qué horario te funciona mejor.',
            ],
            // Post-payment review drip ({google_review_url}/{yelp_review_url}
            // come from the entity - Admin → Entities).
            // Names the tech who did the work: a review that mentions someone by
            // name is what lets us recognize them for it, so {technician} is the
            // point of this copy rather than a flourish. Keep the phrasing
            // gender-neutral - the crew isn't all men.
            // Text 1 when the FIRST call (right after completion) actually
            // connected - warmer than review_request_1 because it references
            // the call dispatch just had, rather than the payment.
            TemplateKey::ReviewConnected->value => [
                $en => 'Hi {first_name}, thanks for taking my call just now - glad to hear everything went well! '
                    .'Reviews are everything for our small business, and they help most right now while the job is '
                    .'still fresh. If you have 30 seconds, would you leave us a quick 5-star rating? '
                    .'{google_review_url} Thank you so much!'
                    .$optOutEn,
                $es => 'Hola {first_name}, gracias por atender mi llamada - nos alegra saber que todo salió bien. '
                    .'Las reseñas son muy importantes para nuestro pequeño negocio, y ayudan más ahora que el trabajo '
                    .'está fresco. ¿Nos dejaría una calificación de 5 estrellas? Solo toma 30 segundos: '
                    .'{google_review_url} ¡Muchas gracias!'
                    .$optOutEs,
            ],
            TemplateKey::ReviewRequest1->value => [
                $en => "Hi {first_name}, \n\n"
                    ."Just confirming your payment went through - you're all set, and thanks again for your business!\n\n"
                    .'One small favor: {technician} worked on your vehicle today and when customers mention our techs '
                    .'by name in a Google review, we recognize and reward them for it. If they earned it, 30 seconds '
                    .'here would make their week: {google_review_url}'
                    .$optOutEn,
                $es => "Hola {first_name}, \n\n"
                    ."Solo confirmamos que tu pago se procesó - ¡todo listo, y gracias de nuevo por tu preferencia!\n\n"
                    .'Un pequeño favor: {technician} trabajó en tu vehículo hoy, y cuando los clientes mencionan a '
                    .'nuestros técnicos por su nombre en una reseña de Google, los reconocemos y los premiamos por ello. '
                    .'Si se lo ganó, 30 segundos aquí le alegrarían la semana: {google_review_url}'
                    .$optOutEs,
            ],
            TemplateKey::ReviewRequest2->value => [
                $en => "Hi {first_name}, it's {location_name}. Just a friendly nudge - if you have a spare moment, a quick Google review would really help us out: {google_review_url} Thank you!".$optOutEn,
                $es => 'Hola {first_name}, te saluda {location_name}. Solo un recordatorio amable - si tienes un momento, una reseña rápida en Google nos ayudaría muchísimo: {google_review_url} ¡Gracias!'.$optOutEs,
            ],
            TemplateKey::ReviewAfterCall->value => [
                $en => "Hi {first_name}, great talking with you just now! Here's the link to leave {location_name} that review: {google_review_url} Thank you so much - it truly helps!".$optOutEn,
                $es => 'Hola {first_name}, ¡qué gusto haber hablado contigo! Aquí está el enlace para dejarle esa reseña a {location_name}: {google_review_url} Muchísimas gracias - ¡de verdad nos ayuda!'.$optOutEs,
            ],
            TemplateKey::ReviewVoicemail->value => [
                $en => 'Hi {first_name}, sorry we missed you! This is {location_name} - we just wanted to check how everything went with your {vehicle} glass service. If you were happy with it, a quick Google review would mean the world to us: {google_review_url}'.$optOutEn,
                $es => 'Hola {first_name}, ¡lamentamos no haberte encontrado! Te saluda {location_name} - queríamos saber cómo te fue con el servicio de vidrio de tu {vehicle}. Si quedaste contento, una reseña rápida en Google significaría muchísimo para nosotros: {google_review_url}'.$optOutEs,
            ],
            TemplateKey::ReviewFinal->value => [
                $en => "Hi {first_name}, one last note from us at {location_name} - if you haven't had the chance yet, here's that review link one more time: {google_review_url} Thanks again for your business!".$optOutEn,
                $es => 'Hola {first_name}, un último mensaje de parte de {location_name} - si aún no has tenido oportunidad, aquí está el enlace de la reseña una vez más: {google_review_url} ¡Gracias de nuevo por tu preferencia!'.$optOutEs,
            ],
            TemplateKey::ReviewThankYou->value => [
                $en => 'Hi {first_name}, we just saw your review - thank you SO much! It really helps our {location_name} team. If you ever need glass work again, you know where to find us.'.$optOutEn,
                $es => 'Hola {first_name}, acabamos de ver tu reseña - ¡muchísimas gracias! De verdad ayuda a nuestro equipo de {location_name}. Si alguna vez necesitas trabajo de vidrio otra vez, ya sabes dónde encontrarnos.'.$optOutEs,
            ],
            // After windshield install on a calibration job - second text after
            // the job-complete / payment family. Partner (the calibration partner) schedules
            // the separate calibration visit directly with the customer.
            TemplateKey::CalibrationPartnerOutreach->value => [
                $en => 'Hi {first_name}, a quick follow-up from {location_name}: your windshield install is done. Our calibration partner will be reaching out shortly to schedule the ADAS calibration visit (a separate appointment for best results). Reply here if you have any questions!',
                $es => 'Hola {first_name}, un seguimiento rápido de {location_name}: la instalación de tu parabrisas ya está lista. Nuestro socio de calibración te contactará en breve para agendar la visita de calibración ADAS (una cita separada para mejores resultados). ¡Responde aquí si tienes alguna pregunta!',
            ],
            // After ops thumbs-up/down confirms the partner finished calibration.
            TemplateKey::CalibrationCompletedConfirm->value => [
                $en => 'Hi {first_name}, this is {location_name}. Just confirming your ADAS calibration is complete - please let us know if everything went well or if you have any concerns. Thank you again for choosing us!',
                $es => 'Hola {first_name}, te saluda {location_name}. Solo confirmamos que tu calibración ADAS está completa - avísanos si todo salió bien o si tienes alguna duda. ¡Gracias de nuevo por elegirnos!',
            ],
        ];
    }
}

<?php

return [
    'created' => [
        'client' => ['subject' => 'Booking request :ref — :spa', 'title' => 'Your request :ref is recorded', 'intro' => 'Thank you :name. :spa will confirm your request shortly. You will receive an e-mail as soon as they reply.'],
        'partner' => ['subject' => 'New request :ref', 'title' => 'New booking request :ref', 'intro' => 'A new request has arrived for :spa. Log in to your partner area to accept or decline it.', 'deadline' => 'Without a reply within :hours h, the request will expire automatically and the slot will be released.'],
    ],
    'confirmed' => [
        'client' => ['subject' => 'Booking confirmed :ref — :spa', 'title' => 'Your booking :ref is confirmed', 'intro' => 'Good news :name, :spa has confirmed your booking. The venue contact details are available on your tracking page.'],
        'partner' => ['subject' => 'Booking :ref confirmed', 'title' => 'Booking :ref confirmed', 'intro' => 'You confirmed this booking for :spa.'],
    ],
    'declined' => [
        'client' => ['subject' => 'Booking :ref declined — :spa', 'title' => 'Your request :ref could not be accepted', 'intro' => 'We are sorry :name, :spa cannot honour this request. You can pick another time or another venue.'],
        'partner' => ['subject' => 'Booking :ref declined', 'title' => 'Booking :ref declined', 'intro' => 'You declined this request for :spa. The slot has been released.'],
    ],
    'cancelled' => [
        'client' => ['subject' => 'Booking :ref cancelled — :spa', 'title' => 'Booking :ref cancelled', 'intro' => 'Your booking at :spa is cancelled.'],
        'partner' => ['subject' => 'Booking :ref cancelled', 'title' => 'Booking :ref cancelled', 'intro' => ':name’s booking at :spa is cancelled. The slot has been released.'],
    ],
    'expired' => [
        'client' => ['subject' => 'Request :ref expired — :spa', 'title' => 'Your request :ref has expired', 'intro' => 'Sorry :name, :spa did not reply in time. Nothing is due; you can renew your request or choose another venue.'],
        'partner' => ['subject' => 'Request :ref expired', 'title' => 'Request :ref expired without reply', 'intro' => ':name’s request for :spa expired without a reply. The slot has been released. Please handle requests faster to avoid losing customers.'],
    ],

    'spa' => [
        'reason' => 'Reason',
        'submitted' => ['subject' => 'New listing to review: :spa', 'title' => 'New listing to review', 'intro' => 'Partner :partner submitted the listing ":spa" (:city) for review.', 'button' => 'Open in admin'],
        'published' => ['subject' => 'Your establishment :spa is live', 'title' => 'Congratulations, :spa is published!', 'intro' => 'Your listing has been approved by our team and is now visible to guests. You will receive booking requests by e-mail.', 'button' => 'Go to my partner area'],
        'refused' => ['subject' => 'Your listing :spa needs changes', 'title' => 'Changes are needed', 'intro' => 'Our team reviewed the listing ":spa" and cannot publish it as is. Fix the points below and submit it again.', 'button' => 'Complete my listing'],
    ],

    'cancellation' => [
        'reason' => 'Reason given by the establishment',
        'decision_note' => 'HammamLuxury comment',
        'response' => ['accepted' => 'accepted', 'refused' => 'refused', 'none' => 'has not answered yet'],
        'button' => ['client' => 'Answer from my booking page', 'partner' => 'View booking', 'admin' => 'Process the request'],
        'requested' => [
            'client' => ['subject' => 'Cancellation request from :spa — booking :ref', 'title' => ':spa asks to cancel your booking :ref', 'intro' => 'Hello :name, :spa has requested the cancellation of your booking. Nothing is cancelled yet: your booking stays confirmed until HammamLuxury makes a decision. Please tell us from your booking page whether you accept or refuse this cancellation.'],
            'admin' => ['subject' => 'Cancellation request :ref — :spa', 'title' => 'New cancellation request', 'intro' => ':spa requests the cancellation of :name’s booking. The customer has been informed; the final decision is yours.'],
        ],
        'client_response' => [
            'admin' => ['subject' => 'Customer answer — cancellation request :ref', 'title' => 'The customer has answered', 'intro' => 'Customer :name :response the cancellation request from :spa. You can now make the final decision.'],
            'partner' => ['subject' => 'Customer answer — cancellation request :ref', 'title' => 'The customer answered your request', 'intro' => 'Customer :name :response your cancellation request. HammamLuxury will make the final decision; the booking stays confirmed until then.'],
        ],
        'refused' => [
            'partner' => ['subject' => 'Cancellation request :ref refused', 'title' => 'Your cancellation request is refused', 'intro' => 'HammamLuxury refused your request to cancel :name’s booking. The booking stays confirmed: please welcome the customer as planned.'],
            'client' => ['subject' => 'Your booking :ref is maintained — :spa', 'title' => 'Your booking is maintained', 'intro' => 'Good news :name: HammamLuxury refused the cancellation request from :spa. Your booking stays confirmed.'],
        ],
        'proposed' => [
            'client' => ['subject' => 'New date proposal — booking :ref', 'title' => 'HammamLuxury proposes a new date', 'intro' => 'Hello :name, following :spa’s request, HammamLuxury proposes to move your booking to the date below. Your current booking stays confirmed until you accept. You can accept or refuse from your booking page.'],
        ],
        'rescheduled' => [
            'client' => ['subject' => 'Your booking :ref has been moved — :spa', 'title' => 'New date confirmed', 'intro' => 'Hello :name, your booking at :spa is confirmed for the new date below.'],
            'partner' => ['subject' => 'Booking :ref moved to a new date', 'title' => 'The customer accepted the new date', 'intro' => 'Customer :name accepted the new date proposed by HammamLuxury. The booking is confirmed for the slot below and the cancellation request is closed.'],
            'admin' => ['subject' => 'New date accepted — booking :ref', 'title' => 'The customer accepted the new date', 'intro' => 'Customer :name accepted the new date for the booking at :spa. The cancellation request is closed.'],
        ],
        'proposal_refused' => [
            'admin' => ['subject' => 'New date refused — cancellation request :ref', 'title' => 'The customer refused the new date', 'intro' => 'Customer :name refused the new date proposed for the booking at :spa. The original booking stays confirmed; the final decision is yours.'],
        ],
        'proposed_date' => 'Proposed new date',
        'reminder' => [
            'admin' => ['subject' => 'Reminder — cancellation request :ref still undecided (appointment tomorrow)', 'title' => 'Cancellation request to handle before the appointment', 'intro' => 'The cancellation request from :spa for booking :ref has not been decided yet and the appointment takes place in less than 24 hours. Please decide before the service.'],
        ],
        'arbitration' => [
            'admin' => ['subject' => 'Decision needed — booking :ref is past with an open cancellation request', 'title' => 'Decision required: service delivered or cancelled?', 'intro' => 'The appointment of booking :ref (:spa) is past while a cancellation request from the venue was still unanswered. Automatic completion and commission are on hold until your decision.'],
        ],
        'proposal_note' => 'HammamLuxury details',
    ],
    'guest_report' => [
        'subject' => 'Guest report — booking :ref (:spa)',
        'title' => 'New guest misconduct report',
        'intro' => ':spa reported an incident concerning the guest of booking :ref. No automatic action is taken: please review the report.',
        'category' => 'Reason',
        'button' => 'View the report',
    ],
    'message' => [
        'button' => 'Open the conversation',
        'footer' => 'Please do not reply to this e-mail: use HammamLuxury messaging so the exchange stays attached to the booking.',
        'partner' => ['subject' => 'New customer message — :ref', 'title' => 'New message from :name', 'intro' => 'The customer of booking :ref at :spa wrote to you:'],
        'client' => ['subject' => 'New message from :spa — booking :ref', 'title' => 'New message from :spa', 'intro' => 'Hello :name, the establishment wrote to you about your booking:'],
    ],

    'review_invite' => [
        'subject' => 'How was your experience at :spa?',
        'title' => 'Your review of :spa',
        'intro' => 'Hello :name, thank you for visiting :spa on :date. Share your experience in a few minutes: your review helps future guests and the venue.',
        'button' => 'Write my review',
        'footer' => 'This link is personal and tied to your booking. Reviews are checked by HammamLuxury before publication.',
    ],

    'billing' => [
        'receipt_line' => 'Your receipt :receipt can be downloaded from your partner area.',
        'statement_available' => [
            'subject' => 'Provisional commission statement — :period',
            'title' => 'Your provisional statement :period is available',
            'intro' => 'The provisional HammamLuxury commission statement of :partner for :period is available in your partner area, Finance section. This document is not an invoice.',
            'window' => 'You can review each line and dispute a commission until the :day of the month inclusive. An open dispute removes only the booking concerned from the statement.',
        ],
        'dispute_opened' => [
            'subject' => '[Billing] Dispute received — :booking',
            'title' => 'New commission dispute',
            'intro' => 'Partner :partner disputes the commission of booking :booking. The line is removed from the billable statement until your decision in the Admin (Finance › Disputes).',
        ],
        'dispute_decided' => [
            'subject' => 'Decision on your dispute — :booking',
            'title' => 'Your dispute has been reviewed',
            'intro' => 'HammamLuxury has decided on the dispute for booking :booking: :decision. Details are available in your partner area, Finance section.',
        ],
        'invoice_issued' => [
            'subject' => 'Invoice :number — HammamLuxury commissions :period',
            'title' => 'Your invoice :number is available',
            'intro' => 'Invoice :number for HammamLuxury commissions of :partner for :period has been issued. The PDF can be downloaded from your partner area, Finance › Invoices. Amount due: :total, payable before :due by bank transfer or in cash against a receipt.',
        ],
        'invoice_pdf_failed' => [
            'subject' => '[Billing] Invoice PDF generation failed — :number',
            'title' => 'Invoice PDF not generated',
            'intro' => 'Invoice :number (:partner, :period) is issued but its PDF could not be generated. No second invoice was created; generation is retried automatically (hl:billing-pdf-retry). The partner will only be notified once the PDF is ready.',
        ],
        'payment_partial' => [
            'subject' => 'Payment recorded on invoice :number — remaining balance :balance',
            'title' => 'Payment of :amount recorded',
            'intro' => 'A payment of :amount (:method, :paid_on) has been recorded on your invoice :number (:period). The remaining balance is :balance. Payment details are available in your partner area, under Finances › Invoices.',
        ],
        'payment_received' => [
            'subject' => 'Invoice :number settled — thank you',
            'title' => 'Your invoice :number is settled',
            'intro' => 'We have received your payment of :amount (:method, :paid_on). Invoice :number (:period) is now fully paid. :receipt_line',
        ],
        'overpayment_detected' => [
            'subject' => '[Billing] Overpayment of :total on invoice :number',
            'title' => 'Overpayment detected',
            'intro' => 'The payment of :amount recorded on invoice :number (:partner) exceeds the amount due by :total. The invoice is settled; the excess is listed under Finances › Overpayments and must be settled manually (no automatic allocation).',
        ],
        'receipt_pdf_failed' => [
            'subject' => '[Billing] Receipt :receipt could not be generated',
            'title' => 'Cash receipt not generated',
            'intro' => 'Receipt :receipt (invoice :number) could not be generated: :error. The payment and receipt number are kept; generation is retried automatically (hl:billing-receipt-retry) and the partner is notified only once the receipt is ready.',
        ],
        'reminder_before_due' => [
            'subject' => 'Reminder — invoice :number due on :due',
            'title' => 'Due in 5 days',
            'intro' => 'Invoice :number (:period) is due on :due. Remaining balance: :balance. Payment by bank transfer or in cash against a receipt, within the legal limit.',
        ],
        'reminder_due' => [
            'subject' => 'Reminder — invoice :number due today',
            'title' => 'Due today',
            'intro' => 'Invoice :number (:period) is due today (:due). Remaining balance: :balance. If your payment is already on its way, please disregard this reminder.',
        ],
        'reminder_late_1' => [
            'subject' => 'Follow-up — invoice :number is overdue',
            'title' => 'Overdue invoice (7 days)',
            'intro' => 'Invoice :number (:period), due on :due, remains unpaid. Remaining balance: :balance. Please proceed with payment or contact us if you are experiencing any difficulty.',
        ],
        'reminder_late_2' => [
            'subject' => 'Final notice — invoice :number (15 days overdue)',
            'title' => 'Overdue invoice (15 days)',
            'intro' => 'Despite our reminders, invoice :number (:period), due on :due, remains unpaid. Remaining balance: :balance. Failing payment, HammamLuxury may suspend the visibility of your venues in accordance with the partner terms.',
        ],
        'invoice_late' => [
            'subject' => '[Billing] Invoice :number unpaid for 15 days — :partner',
            'title' => 'Unpaid at 15 days',
            'intro' => 'Invoice :number (:partner, :period), due on :due, remains unpaid after the final notice. Balance due: :balance.',
        ],
        'footer' => 'Automatic message from the HammamLuxury platform; real invoicing is not active until its activation has been validated.',
    ],
];

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
    ],
    'message' => [
        'button' => 'Open the conversation',
        'footer' => 'Please do not reply to this e-mail: use HammamLuxury messaging so the exchange stays attached to the booking.',
        'partner' => ['subject' => 'New customer message — :ref', 'title' => 'New message from :name', 'intro' => 'The customer of booking :ref at :spa wrote to you:'],
        'client' => ['subject' => 'New message from :spa — booking :ref', 'title' => 'New message from :spa', 'intro' => 'Hello :name, the establishment wrote to you about your booking:'],
    ],
];

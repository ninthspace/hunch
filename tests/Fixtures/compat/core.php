<?php

use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;

/*
 * The core compatibility group: 24 invented support messages for a clothing
 * shop, each labelled for all three question types. Nothing here is real:
 * no real person, address, order or contact detail appears.
 */
return [
    'context' => 'A support inbox for a clothing shop.',
    'questions' => [
        'intent' => new Choice('What does the customer want?', [
            'refund' => 'Money back for an order',
            'exchange' => 'A different size, colour or item',
            'delivery' => 'To find out where an order is, or to change delivery',
            'other' => 'Anything else',
        ]),
        'urgency' => new Score('How urgent is this message?', [
            'low' => 'It can wait a few days',
            'medium' => 'It should be answered today or tomorrow',
            'high' => 'It needs an answer within the hour',
        ]),
        'complaint' => new Boolean('Is the customer complaining?', [
            'true' => 'They are unhappy with the product or the service',
            'false' => 'They are asking or telling, without complaint',
        ]),
    ],
    'items' => [
        ['id' => 'core-01', 'state' => 'The jumper arrived with a hole in the sleeve. I would like my money back please.', 'labels' => ['intent' => 'refund', 'urgency' => 'medium', 'complaint' => true]],
        ['id' => 'core-02', 'state' => 'Could I swap the blue shirt for the same one in green? Happy to post it back.', 'labels' => ['intent' => 'exchange', 'urgency' => 'low', 'complaint' => false]],
        ['id' => 'core-03', 'state' => 'Where is my parcel? It was due last Tuesday and nothing has arrived.', 'labels' => ['intent' => 'delivery', 'urgency' => 'medium', 'complaint' => true]],
        ['id' => 'core-04', 'state' => 'Do you restock the wool socks in winter? No rush, just curious.', 'labels' => ['intent' => 'other', 'urgency' => 'low', 'complaint' => false]],
        ['id' => 'core-05', 'state' => 'I need these trousers refunded today. I fly at six tonight and cannot take them.', 'labels' => ['intent' => 'refund', 'urgency' => 'high', 'complaint' => false]],
        ['id' => 'core-06', 'state' => 'The coat is far too small. Can you send the next size up instead?', 'labels' => ['intent' => 'exchange', 'urgency' => 'low', 'complaint' => false]],
        ['id' => 'core-07', 'state' => 'Nobody has answered me in four days about a missing delivery. This is unacceptable.', 'labels' => ['intent' => 'delivery', 'urgency' => 'high', 'complaint' => true]],
        ['id' => 'core-08', 'state' => 'Are your cotton shirts machine washable, or should they be washed by hand?', 'labels' => ['intent' => 'other', 'urgency' => 'low', 'complaint' => false]],
        ['id' => 'core-09', 'state' => 'The dress faded badly after one wash. I want a refund, not a replacement.', 'labels' => ['intent' => 'refund', 'urgency' => 'medium', 'complaint' => true]],
        ['id' => 'core-10', 'state' => 'Please change my delivery to the depot instead of my flat. The driver keeps missing me.', 'labels' => ['intent' => 'delivery', 'urgency' => 'medium', 'complaint' => false]],
        ['id' => 'core-11', 'state' => 'Wrong item in the box entirely. I ordered a scarf and got a belt. Send the scarf.', 'labels' => ['intent' => 'exchange', 'urgency' => 'medium', 'complaint' => true]],
        ['id' => 'core-12', 'state' => 'Just saying thank you, the boots are lovely and arrived quickly.', 'labels' => ['intent' => 'other', 'urgency' => 'low', 'complaint' => false]],
        ['id' => 'core-13', 'state' => 'Cancel the order and refund me. I found the same coat cheaper elsewhere.', 'labels' => ['intent' => 'refund', 'urgency' => 'medium', 'complaint' => false]],
        ['id' => 'core-14', 'state' => 'The zip broke the first time I wore the jacket. Utterly shoddy. Refund please.', 'labels' => ['intent' => 'refund', 'urgency' => 'medium', 'complaint' => true]],
        ['id' => 'core-15', 'state' => 'My order says delivered but there is nothing here. I need it before the wedding on Saturday.', 'labels' => ['intent' => 'delivery', 'urgency' => 'high', 'complaint' => true]],
        ['id' => 'core-16', 'state' => 'Can I exchange a gift without the receipt? It was bought for me in the sale.', 'labels' => ['intent' => 'exchange', 'urgency' => 'low', 'complaint' => false]],
        ['id' => 'core-17', 'state' => 'What time does your shop close on bank holidays?', 'labels' => ['intent' => 'other', 'urgency' => 'low', 'complaint' => false]],
        ['id' => 'core-18', 'state' => 'Third time asking: where is the refund you promised two weeks ago?', 'labels' => ['intent' => 'refund', 'urgency' => 'high', 'complaint' => true]],
        ['id' => 'core-19', 'state' => 'The shoes rub terribly. I would rather have the wider fit if you do one.', 'labels' => ['intent' => 'exchange', 'urgency' => 'low', 'complaint' => true]],
        ['id' => 'core-20', 'state' => 'Could you hold my parcel until Friday? I am away until then.', 'labels' => ['intent' => 'delivery', 'urgency' => 'medium', 'complaint' => false]],
        ['id' => 'core-21', 'state' => 'Do you sell gift cards, and can they be used online as well as in store?', 'labels' => ['intent' => 'other', 'urgency' => 'low', 'complaint' => false]],
        ['id' => 'core-22', 'state' => 'The parcel was left in the rain and the box is soaked through. Everything is ruined.', 'labels' => ['intent' => 'delivery', 'urgency' => 'high', 'complaint' => true]],
        ['id' => 'core-23', 'state' => 'Wrong size sent again. Please just refund the whole thing, I have lost patience.', 'labels' => ['intent' => 'refund', 'urgency' => 'high', 'complaint' => true]],
        ['id' => 'core-24', 'state' => 'Is the linen shirt true to size, or should I order one size larger?', 'labels' => ['intent' => 'other', 'urgency' => 'low', 'complaint' => false]],
    ],
];

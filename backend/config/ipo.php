<?php

/*
 * Indian Patent Office territorial jurisdiction (Patents Rules, 2003, rule 4 read with
 * the Controller's notification on the appropriate office). An applicant's appropriate
 * office follows their place of residence / business in India; applicants with no
 * Indian address are decided by the address for service, chosen in the Form 1 dialog.
 *
 * Keys are normalised state / UT names (lower case, '&' → 'and', no punctuation).
 * Update this list if the firm's practice list differs.
 */
return [
    'offices' => ['Chennai', 'Mumbai', 'Delhi', 'Kolkata'],

    'state_office' => [
        // Chennai
        'tamil nadu' => 'Chennai', 'kerala' => 'Chennai', 'karnataka' => 'Chennai',
        'andhra pradesh' => 'Chennai', 'telangana' => 'Chennai', 'puducherry' => 'Chennai',
        'pondicherry' => 'Chennai', 'lakshadweep' => 'Chennai',
        // Mumbai
        'maharashtra' => 'Mumbai', 'gujarat' => 'Mumbai', 'madhya pradesh' => 'Mumbai',
        'goa' => 'Mumbai', 'chhattisgarh' => 'Mumbai',
        'dadra and nagar haveli and daman and diu' => 'Mumbai', 'dadra and nagar haveli' => 'Mumbai',
        'daman and diu' => 'Mumbai',
        // Delhi
        'delhi' => 'Delhi', 'nct of delhi' => 'Delhi', 'new delhi' => 'Delhi', 'haryana' => 'Delhi',
        'punjab' => 'Delhi', 'himachal pradesh' => 'Delhi', 'jammu and kashmir' => 'Delhi',
        'ladakh' => 'Delhi', 'rajasthan' => 'Delhi', 'uttar pradesh' => 'Delhi',
        'uttarakhand' => 'Delhi', 'uttaranchal' => 'Delhi', 'chandigarh' => 'Delhi',
        // Kolkata
        'west bengal' => 'Kolkata', 'bihar' => 'Kolkata', 'jharkhand' => 'Kolkata',
        'odisha' => 'Kolkata', 'orissa' => 'Kolkata', 'sikkim' => 'Kolkata', 'assam' => 'Kolkata',
        'arunachal pradesh' => 'Kolkata', 'manipur' => 'Kolkata', 'meghalaya' => 'Kolkata',
        'mizoram' => 'Kolkata', 'nagaland' => 'Kolkata', 'tripura' => 'Kolkata',
        'andaman and nicobar islands' => 'Kolkata', 'andaman and nicobar' => 'Kolkata',
    ],
];

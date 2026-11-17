<p class="muted small">Items in brackets must be completed with the company’s legal details before going live.</p>

<h2>1. Publisher</h2>
<p>The website hammamluxury.com (“the Platform”) is published by @php($legal = \App\Support\LegalIdentity::values())<b>{!! $legal['company_name'] !!}</b>, {!! $legal['legal_form'] !!}{!! $legal['capital'] ? ' with a share capital of '.$legal['capital'].' MAD' : '' !!}, registered with the Trade Register of {!! $legal['rc_city'] !!} under no. {!! $legal['rc_number'] !!}, ICE {!! $legal['ice'] !!}, tax ID {!! $legal['tax_id'] !!}@if($legal['patente']), business tax no. {!! $legal['patente'] !!}@endif, with its registered office at {!! $legal['address'] !!}, Morocco. Trade name: HammamLuxury.</p>
<p>Publication director: {!! $legal['publication_director'] !!}.</p>
<p>Contact: <a href="{{ route('contact') }}">contact form</a> · e-mail {!! $legal['email'] !!}@if($legal['phone']) · phone {!! $legal['phone'] !!}@endif.</p>

<h2>2. Hosting</h2>
<p>The Platform is hosted by Hetzner Online GmbH, Industriestr. 25, 91710 Gunzenhausen, Germany. Backups are kept within the European Union.</p>

<h2>3. Activity</h2>
<p>HammamLuxury connects customers with independent hammams, spas and wellness centres (“the Establishments”). Treatments are performed and invoiced by the Establishments under their sole responsibility; HammamLuxury does not collect any payment from customers. The applicable conditions are set out in the <a href="{{ route('page', 'cgu') }}">terms of use</a>.</p>

<h2>4. Intellectual property</h2>
<p>The structure of the site, its texts, logos and graphic elements are the property of HammamLuxury. Photographs, descriptions and trademarks of the Establishments remain the property of their respective owners. For referenced Establishments, these elements come from their public communication materials (official website, Google listing) and are reproduced solely to present the Establishment; HammamLuxury claims no rights over them. Any rights holder may request their modification or removal at {!! $legal['email'] !!}; requests are handled within 48 business hours. Any unauthorised reproduction of the site is prohibited.</p>

<h2>5. Personal data and cookies</h2>
<p>The processing of personal data and the use of cookies are described in the <a href="{{ route('page', 'confidentialite') }}">privacy policy</a>. HammamLuxury is the data controller under Moroccan law no. 09-08{!! $legal['cndp'] ? ' (CNDP: '.$legal['cndp'].')' : '' !!}.</p>

<h2>6. Liability</h2>
<p>HammamLuxury strives to keep information accurate and up to date (treatments, prices, opening hours, location) as provided by the Establishments; it cannot be held liable for errors or omissions attributable to them, nor for the performance of the services. External links (Google Maps, Establishment websites) are the responsibility of their publishers.</p>

<h2>7. Governing law</h2>
<p>This legal notice is governed by Moroccan law. Any dispute falls under the jurisdiction of the competent courts of {!! $legal['court_city'] !!}, subject to mandatory consumer protection provisions.</p>

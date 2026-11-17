@php($legal = \App\Support\LegalIdentity::values())
@if($legal['incomplete'])<p class="muted small">Les mentions entre crochets sont à compléter avec les informations juridiques de la société avant la mise en ligne.</p>@endif

<h2>1. Éditeur du site</h2>
<p>Le site hammamluxury.com (« la Plateforme ») est édité par <b>{!! $legal['company_name'] !!}</b>, {!! $legal['legal_form'] !!}{!! $legal['capital'] ? ' au capital de '.$legal['capital'].' MAD' : '' !!}, immatriculée au Registre du commerce de {!! $legal['rc_city'] !!} sous le n° {!! $legal['rc_number'] !!}, ICE {!! $legal['ice'] !!}, identifiant fiscal {!! $legal['tax_id'] !!}@if($legal['patente']), taxe professionnelle n° {!! $legal['patente'] !!}@endif, dont le siège social est situé {!! $legal['address'] !!}, Maroc. Nom commercial : HammamLuxury.</p>
<p>Directeur de la publication : {!! $legal['publication_director'] !!}.</p>
<p>Contact : <a href="{{ route('contact') }}">formulaire de contact</a> · e-mail {!! $legal['email'] !!}@if($legal['phone']) · téléphone {!! $legal['phone'] !!}@endif.</p>

<h2>2. Hébergement</h2>
<p>La Plateforme est hébergée par Hetzner Online GmbH, Industriestr. 25, 91710 Gunzenhausen, Allemagne. Les sauvegardes sont conservées dans l’Union européenne.</p>

<h2>3. Activité</h2>
<p>HammamLuxury met en relation des clients et des hammams, spas et centres de bien-être indépendants (« les Établissements »). Les prestations sont réalisées et facturées par les Établissements, sous leur seule responsabilité ; HammamLuxury n’encaisse aucun paiement de la part des clients. Les conditions applicables figurent dans les <a href="{{ route('page', 'cgu') }}">conditions générales d’utilisation</a>.</p>

<h2>4. Propriété intellectuelle</h2>
<p>La structure du site, ses textes, logos et éléments graphiques sont la propriété de HammamLuxury. Les photographies, descriptions et marques des Établissements restent la propriété de leurs titulaires respectifs. Pour les Établissements référencés, ces éléments proviennent de leurs supports de communication publics (site officiel, fiche Google) et sont reproduits dans le seul but de présenter l’Établissement ; HammamLuxury ne revendique aucun droit sur ces éléments. Tout titulaire de droits peut en demander la modification ou le retrait à {!! $legal['email'] !!} ; la demande est traitée sous 48 heures ouvrées. Toute reproduction non autorisée du site est interdite.</p>

<h2>5. Données personnelles et cookies</h2>
<p>Le traitement des données personnelles et l’usage des cookies sont décrits dans la <a href="{{ route('page', 'confidentialite') }}">politique de confidentialité</a>. HammamLuxury est responsable de traitement au sens de la loi n° 09-08{!! $legal['cndp'] ? ' (CNDP : '.$legal['cndp'].')' : '' !!}.</p>

<h2>6. Responsabilité</h2>
<p>HammamLuxury s’efforce de maintenir des informations exactes et à jour (soins, tarifs, horaires, localisation), fournies par les Établissements ; elle ne saurait être tenue responsable des erreurs ou omissions imputables à ces derniers, ni de l’exécution des prestations. Les liens externes (Google Maps, sites des Établissements) relèvent de la responsabilité de leurs éditeurs.</p>

<h2>7. Droit applicable</h2>
<p>Les présentes mentions sont soumises au droit marocain. Tout litige relève des tribunaux compétents de {!! $legal['court_city'] !!}, sous réserve des dispositions impératives applicables aux consommateurs.</p>

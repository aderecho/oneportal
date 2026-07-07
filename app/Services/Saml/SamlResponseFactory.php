<?php

namespace App\Services\Saml;

use App\Models\ServiceProvider;
use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

class SamlResponseFactory
{
    public function makeBase64Response(User $user, ServiceProvider $provider, ?string $inResponseTo = null): string
    {
        $now = now()->utc();
        $expiresAt = $now->copy()->addMinutes(5);
        $responseId = '_'.Str::uuid()->toString();
        $assertionId = '_'.Str::uuid()->toString();
        $issuer = config('app.url').'/saml2/metadata';

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $response = $document->createElementNS('urn:oasis:names:tc:SAML:2.0:protocol', 'samlp:Response');
        $response->setAttribute('xmlns:saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $response->setAttribute('ID', $responseId);
        $response->setAttribute('Version', '2.0');
        $response->setAttribute('IssueInstant', $now->toIso8601ZuluString());
        $response->setAttribute('Destination', $provider->acs_url);
        if ($inResponseTo) {
            $response->setAttribute('InResponseTo', $inResponseTo);
        }
        $document->appendChild($response);

        $response->appendChild($this->element($document, 'saml:Issuer', $issuer));

        $status = $document->createElement('samlp:Status');
        $statusCode = $document->createElement('samlp:StatusCode');
        $statusCode->setAttribute('Value', 'urn:oasis:names:tc:SAML:2.0:status:Success');
        $status->appendChild($statusCode);
        $response->appendChild($status);

        $assertion = $document->createElement('saml:Assertion');
        $assertion->setAttribute('ID', $assertionId);
        $assertion->setAttribute('Version', '2.0');
        $assertion->setAttribute('IssueInstant', $now->toIso8601ZuluString());
        $response->appendChild($assertion);

        $assertion->appendChild($this->element($document, 'saml:Issuer', $issuer));

        $subject = $document->createElement('saml:Subject');
        $nameId = $this->element($document, 'saml:NameID', $user->email);
        $nameId->setAttribute('Format', 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress');
        $subject->appendChild($nameId);

        $confirmation = $document->createElement('saml:SubjectConfirmation');
        $confirmation->setAttribute('Method', 'urn:oasis:names:tc:SAML:2.0:cm:bearer');
        $confirmationData = $document->createElement('saml:SubjectConfirmationData');
        $confirmationData->setAttribute('Recipient', $provider->acs_url);
        $confirmationData->setAttribute('NotOnOrAfter', $expiresAt->toIso8601ZuluString());
        if ($inResponseTo) {
            $confirmationData->setAttribute('InResponseTo', $inResponseTo);
        }
        $confirmation->appendChild($confirmationData);
        $subject->appendChild($confirmation);
        $assertion->appendChild($subject);

        $conditions = $document->createElement('saml:Conditions');
        $conditions->setAttribute('NotBefore', $now->copy()->subMinute()->toIso8601ZuluString());
        $conditions->setAttribute('NotOnOrAfter', $expiresAt->toIso8601ZuluString());
        $audienceRestriction = $document->createElement('saml:AudienceRestriction');
        $audienceRestriction->appendChild($this->element($document, 'saml:Audience', $provider->entity_id));
        $conditions->appendChild($audienceRestriction);
        $assertion->appendChild($conditions);

        $authnStatement = $document->createElement('saml:AuthnStatement');
        $authnStatement->setAttribute('AuthnInstant', $now->toIso8601ZuluString());
        $authnStatement->setAttribute('SessionIndex', $responseId);
        $authnContext = $document->createElement('saml:AuthnContext');
        $authnContext->appendChild($this->element(
            $document,
            'saml:AuthnContextClassRef',
            'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport',
        ));
        $authnStatement->appendChild($authnContext);
        $assertion->appendChild($authnStatement);

        $attributeStatement = $document->createElement('saml:AttributeStatement');
        foreach ($this->attributesFor($user, $provider) as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $attribute = $document->createElement('saml:Attribute');
            $attribute->setAttribute('Name', $name);
            $attribute->appendChild($this->element($document, 'saml:AttributeValue', (string) $value));
            $attributeStatement->appendChild($attribute);
        }
        $assertion->appendChild($attributeStatement);

        return base64_encode($this->signResponse($document, $response));
    }

    private function signResponse(DOMDocument $document, DOMElement $response): string
    {
        [$privateKey, $privateKeyIsFile] = $this->signingMaterial(
            (string) config('services.saml.signing_private_key', ''),
            (string) config('services.saml.signing_private_key_path', storage_path('saml/certs/sp-private.key')),
            'private key'
        );
        [$certificate, $certificateIsFile] = $this->signingMaterial(
            (string) config('services.saml.signing_public_cert', ''),
            (string) config('services.saml.signing_public_cert_path', storage_path('saml/certs/sp-public.crt')),
            'public certificate'
        );

        $issuer = null;
        foreach ($response->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'Issuer') {
                $issuer = $child;
                break;
            }
        }

        $signature = new XMLSecurityDSig();
        $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $signature->addReference(
            $response,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'force_uri' => true]
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($privateKey, $privateKeyIsFile);

        $signature->sign($key);
        $signature->add509Cert($certificateIsFile ? (string) file_get_contents($certificate) : $certificate, true, false);
        $signature->insertSignature($response, $issuer?->nextSibling);

        return $document->saveXML();
    }

    private function signingMaterial(string $inlinePem, string $path, string $label): array
    {
        if ($inlinePem !== '') {
            return [$inlinePem, false];
        }

        throw_unless(is_readable($path), new \RuntimeException("SAML signing {$label} is not readable at {$path}."));

        return [$path, true];
    }

    private function element(DOMDocument $document, string $name, string $value): \DOMElement
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));

        return $element;
    }

    private function attributesFor(User $user, ServiceProvider $provider): array
    {
        $available = [
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'department' => $user->department?->name,
        ];

        $allowed = $provider->attribute_release ?: ['email', 'name', 'role'];

        return collect($available)
            ->only($allowed)
            ->all();
    }
}

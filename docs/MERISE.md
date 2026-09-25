# Conception Merise de la plateforme GFP

*Ministère de la Fonction Publique et de la Modernisation de l’Administration — Côte d’Ivoire*

Ce document décrit la plateforme avec la méthode Merise : modèles conceptuels des données (MCD), des traitements (MCT) et organisationnel des traitements (MOT). Les schémas sont en Mermaid : GitHub les affiche directement.

Version PDF : [Conception_Merise_GFP.pdf](Conception_Merise_GFP.pdf)

## Lire les schémas

**MCD — Modèle Conceptuel des Données.** Décrit les informations manipulées : les entités (rectangles verts, identifiant souligné), les associations qui les relient (ovales orange, avec leurs propres attributs) et les cardinalités. Une cardinalité « 0,n » se lit : une occurrence de l’entité peut participer de zéro à plusieurs fois à l’association ; « 1,1 » : exactement une fois.

**MCT — Modèle Conceptuel des Traitements.** Décrit ce que fait le système, sans dire qui ni où : des événements (ovales orange) déclenchent des opérations (rectangles verts, avec leurs règles de gestion) qui produisent des résultats (hexagones gris, rouges pour un refus ou un rejet). Les branches portent la condition d’émission du résultat.

**MOT — Modèle Organisationnel des Traitements.** Précise qui fait quoi : chaque opération du MCT est affectée à un poste de travail (couloir) avec sa nature (manuelle, interactive ou automatique), sa durée et ses règles. Le schéma en couloirs donne les échanges entre les postes ; le tableau donne le détail de chaque opération.

## 1. Modèles conceptuels des données (MCD)

### MCD 1 — Organisation, comptes et droits

Un agent appartient à au plus une structure (Direction Générale, Direction, Sous-Direction…) et occupe au plus une fonction. Une structure peut dépendre d’une autre (hiérarchie du ministère) et avoir un agent responsable : c’est lui qui donne le visa sur les permissions de ses agents. Un agent peut avoir un compte utilisateur ; un compte porte un ou plusieurs rôles ; chaque rôle ouvre des privilèges.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef ent fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef asso fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  AG["<b>AGENT</b><hr/><u>id_agent</u><br/>matricule<br/>civilité<br/>nom<br/>prénom<br/>date_naissance<br/>sexe<br/>téléphone<br/>email<br/>solde_permission_annuel"]:::ent
  ST["<b>STRUCTURE</b><hr/><u>id_structure</u><br/>code<br/>nom<br/>sigle<br/>type<br/>officielle"]:::ent
  FO["<b>FONCTION</b><hr/><u>id_fonction</u><br/>code<br/>libellé<br/>niveau_hiérarchique"]:::ent
  US["<b>UTILISATEUR</b><hr/><u>id_utilisateur</u><br/>matricule<br/>mot_de_passe (haché)<br/>actif<br/>dernière_connexion"]:::ent
  RO["<b>RÔLE</b><hr/><u>id_rôle</u><br/>code<br/>libellé<br/>description"]:::ent
  PR["<b>PRIVILÈGE</b><hr/><u>id_privilège</u><br/>code<br/>libellé<br/>module"]:::ent
  A1(["<b>APPARTENIR</b>"]):::asso
  A2(["<b>OCCUPER</b>"]):::asso
  A3(["<b>DIRIGER</b>"]):::asso
  A4(["<b>DÉPENDRE</b>"]):::asso
  A5(["<b>POSSÉDER</b>"]):::asso
  A6(["<b>AVOIR</b>"]):::asso
  A7(["<b>ACCORDER</b>"]):::asso
  AG ---|"0,1"| A1
  A1 ---|"0,n"| ST
  AG ---|"0,1"| A2
  A2 ---|"0,n"| FO
  AG ---|"0,n"| A3
  A3 ---|"0,1"| ST
  ST ---|"0,1 (parent)"| A4
  A4 ---|"0,n (enfant)"| ST
  AG ---|"0,1"| A5
  A5 ---|"1,1"| US
  US ---|"1,n"| A6
  A6 ---|"0,n"| RO
  RO ---|"0,n"| A7
  A7 ---|"0,n"| PR
```

### MCD 2 — Demandes de permission

Une demande est déposée par un agent, relève d’un type de permission et porte un justificatif. Trois agents interviennent sur elle à titre différent : le gestionnaire RH (vérification et notification), le responsable de structure Sous-Directeur ou Directeur (visa, uniquement si la durée est de 2 jours ou moins) et le DRH (décision finale). Chaque changement d’état est conservé dans l’historique, et l’agent est alerté par une notification.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef ent fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef asso fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  AG["<b>AGENT</b><hr/><u>id_agent</u><br/>matricule<br/>nom<br/>prénom<br/>solde_permission_annuel"]:::ent
  TP["<b>TYPE_PERMISSION</b><hr/><u>id_type</u><br/>libellé<br/>durée_max"]:::ent
  DP["<b>DEMANDE_PERMISSION</b><hr/><u>id_demande</u><br/>code_dossier<br/>date_début<br/>date_fin<br/>nombre_jours<br/>motif<br/>statut<br/>motif_retour<br/>motif_rejet"]:::ent
  PJ["<b>PIÈCE_JOINTE</b><hr/><u>id_pièce</u><br/>nom_fichier<br/>type_mime<br/>chemin_stockage"]:::ent
  HD["<b>HISTORIQUE_DEMANDE</b><hr/><u>id_historique</u><br/>action<br/>ancien_statut<br/>nouveau_statut<br/>commentaire<br/>date_action"]:::ent
  NO["<b>NOTIFICATION</b><hr/><u>id_notification</u><br/>titre<br/>message<br/>type<br/>est_lu"]:::ent
  B1(["<b>DEMANDER</b>"]):::asso
  B2(["<b>QUALIFIER</b>"]):::asso
  B3(["<b>JUSTIFIER</b>"]):::asso
  B4(["<b>VÉRIFIER</b><hr/><i>avis_gestionnaire<br/>date_vérification</i>"]):::asso
  B5(["<b>VISER</b><hr/><i>visa_attendu<br/>avis_direction<br/>date_visa</i>"]):::asso
  B6(["<b>DÉCIDER</b><hr/><i>décision_DRH<br/>date_décision</i>"]):::asso
  B7(["<b>NOTIFIER</b><hr/><i>date_notification</i>"]):::asso
  B8(["<b>TRACER</b>"]):::asso
  B9(["<b>AGIR</b>"]):::asso
  B10(["<b>ALERTER</b>"]):::asso
  AG ---|"0,n"| B1
  B1 ---|"1,1"| DP
  TP ---|"0,n"| B2
  B2 ---|"1,1"| DP
  DP ---|"1,1"| B3
  B3 ---|"0,1"| PJ
  AG ---|"0,n (gestionnaire RH)"| B4
  B4 ---|"0,1"| DP
  AG ---|"0,n (Sous-Dir. / Directeur)"| B5
  B5 ---|"0,1"| DP
  AG ---|"0,n (DRH)"| B6
  B6 ---|"0,1"| DP
  AG ---|"0,n (gestionnaire RH)"| B7
  B7 ---|"0,1"| DP
  DP ---|"0,n"| B8
  B8 ---|"1,1"| HD
  AG ---|"0,n"| B9
  B9 ---|"0,n"| HD
  AG ---|"0,n"| B10
  B10 ---|"1,1"| NO
```

### MCD 3 — Déclarations de naissance et de décès

Les deux déclarations ont la même structure et le même circuit ; elles diffèrent par leurs informations propres et par la pièce obligatoire (extrait d’acte de naissance ou certificat de décès). L’agent déclare, le gestionnaire RH contrôle les pièces, le DRH valide ou rejette avec un motif. Chaque étape est tracée dans l’historique.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef ent fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef asso fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  AG["<b>AGENT</b><hr/><u>id_agent</u><br/>matricule<br/>nom<br/>prénom"]:::ent
  DN["<b>DÉCLARATION_NAISSANCE</b><hr/><u>id_déclaration</u><br/>code_dossier<br/>nom_enfant<br/>prénom_enfant<br/>date_naissance_enfant<br/>lieu_naissance_enfant<br/>statut<br/>motif_retour<br/>motif_rejet"]:::ent
  DD["<b>DÉCLARATION_DÉCÈS</b><hr/><u>id_déclaration</u><br/>code_dossier<br/>nom_défunt<br/>prénom_défunt<br/>lien_parenté<br/>date_décès<br/>lieu_décès<br/>statut<br/>motif_retour<br/>motif_rejet"]:::ent
  PJ["<b>PIÈCE_JOINTE</b><hr/><u>id_pièce</u><br/>nom_fichier<br/>type_mime<br/>chemin_stockage"]:::ent
  HC["<b>HISTORIQUE_DÉCLARATION</b><hr/><u>id_historique</u><br/>type_dossier<br/>action<br/>ancien_statut<br/>nouveau_statut<br/>commentaire<br/>date_action"]:::ent
  C1(["<b>DÉCLARER_NAISSANCE</b>"]):::asso
  C2(["<b>DÉCLARER_DÉCÈS</b>"]):::asso
  C3(["<b>VALIDER_NAISSANCE</b><hr/><i>date_validation</i>"]):::asso
  C4(["<b>VALIDER_DÉCÈS</b><hr/><i>date_validation</i>"]):::asso
  C5(["<b>JUSTIFIER</b>"]):::asso
  C6(["<b>TRACER</b>"]):::asso
  C7(["<b>AGIR</b>"]):::asso
  AG ---|"0,n"| C1
  C1 ---|"1,1"| DN
  AG ---|"0,n"| C2
  C2 ---|"1,1"| DD
  AG ---|"0,n (DRH)"| C3
  C3 ---|"0,1"| DN
  AG ---|"0,n (DRH)"| C4
  C4 ---|"0,1"| DD
  DN ---|"1,1"| C5
  DD ---|"1,1"| C5
  C5 ---|"0,n"| PJ
  DN ---|"0,n"| C6
  DD ---|"0,n"| C6
  C6 ---|"1,1"| HC
  AG ---|"0,n"| C7
  C7 ---|"0,n"| HC
```

### MCD 4 — Notes de service

Une note est émise par une autorité (DRH, Directeur de Cabinet, Directeur ou Sous-Directeur), saisie par la secrétaire, éventuellement validée par l’autorité, puis destinée à une ou plusieurs structures. Ses destinataires sont les Directeurs, Sous-Directeurs, Chefs de service et agents de ces structures ; ils sont alertés par notification et par email.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef ent fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef asso fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  AG["<b>AGENT</b><hr/><u>id_agent</u><br/>matricule<br/>nom<br/>prénom"]:::ent
  NS["<b>NOTE_SERVICE</b><hr/><u>id_note</u><br/>numéro_référence<br/>objet<br/>contenu<br/>fichier<br/>statut<br/>date_émission<br/>date_diffusion"]:::ent
  ST["<b>STRUCTURE</b><hr/><u>id_structure</u><br/>code<br/>nom<br/>sigle"]:::ent
  HN["<b>HISTORIQUE_NOTE</b><hr/><u>id_historique</u><br/>action<br/>ancien_statut<br/>nouveau_statut<br/>commentaire<br/>date_action"]:::ent
  NO["<b>NOTIFICATION</b><hr/><u>id_notification</u><br/>titre<br/>message<br/>type<br/>est_lu"]:::ent
  D1(["<b>ÉMETTRE</b>"]):::asso
  D2(["<b>SAISIR</b><hr/><i>date_saisie</i>"]):::asso
  D3(["<b>VALIDER</b><hr/><i>date_validation</i>"]):::asso
  D4(["<b>DESTINER</b>"]):::asso
  D5(["<b>TRACER</b>"]):::asso
  D6(["<b>ALERTER</b>"]):::asso
  AG ---|"0,n (autorité)"| D1
  D1 ---|"1,1"| NS
  AG ---|"0,n (secrétaire)"| D2
  D2 ---|"0,1"| NS
  AG ---|"0,n (autorité)"| D3
  D3 ---|"0,1"| NS
  NS ---|"1,n"| D4
  D4 ---|"0,n"| ST
  NS ---|"0,n"| D5
  D5 ---|"1,1"| HN
  AG ---|"0,n"| D6
  D6 ---|"1,1"| NO
```

### MCD 5 — Sécurité, audit et notifications

Toute entrée, sortie ou action sensible est inscrite au journal d’audit. Le lien avec l’utilisateur est facultatif : une tentative de connexion avec un matricule inconnu est aussi journalisée. Une demande de réinitialisation de mot de passe est adressée par un utilisateur et traitée (autorisée ou refusée) par un administrateur.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef ent fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef asso fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  AG["<b>AGENT</b><hr/><u>id_agent</u><br/>matricule<br/>nom<br/>prénom"]:::ent
  US["<b>UTILISATEUR</b><hr/><u>id_utilisateur</u><br/>matricule<br/>mot_de_passe (haché)<br/>actif<br/>dernière_connexion"]:::ent
  DR["<b>DEMANDE_RÉINITIALISATION</b><hr/><u>id_demande</u><br/>code_de_suivi (haché)<br/>statut<br/>date_utilisation"]:::ent
  JA["<b>JOURNAL_AUDIT</b><hr/><u>id_événement</u><br/>matricule<br/>nom<br/>rôle<br/>catégorie<br/>action<br/>description<br/>référence<br/>réussi<br/>adresse_IP<br/>appareil<br/>date_heure"]:::ent
  NO["<b>NOTIFICATION</b><hr/><u>id_notification</u><br/>titre<br/>message<br/>type<br/>est_lu"]:::ent
  E1(["<b>RÉINITIALISER</b>"]):::asso
  E2(["<b>TRAITER</b><hr/><i>date_traitement</i>"]):::asso
  E3(["<b>JOURNALISER</b>"]):::asso
  E4(["<b>ALERTER</b>"]):::asso
  US ---|"0,n"| E1
  E1 ---|"1,1"| DR
  US ---|"0,n (administrateur)"| E2
  E2 ---|"0,1"| DR
  US ---|"0,n"| E3
  E3 ---|"0,1"| JA
  AG ---|"0,n"| E4
  E4 ---|"1,1"| NO
```

## 2. Modèles conceptuels des traitements (MCT)

### MCT — Permission, cas 1 : 2 jours ou moins (1/2) — dépôt et vérification

L’agent dépose sa demande ; le gestionnaire RH la vérifie. Il la transmet (conforme), la retourne pour correction (incomplète) ou la rejette (non conforme). La suite du circuit est au schéma suivant.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  E1(["<b>É1</b> — L’agent dépose une demande<br/>(durée ≤ 2 jours)"]):::ev
  O1["<b>OP1 — Enregistrer la demande</b><hr/>durée entre 1 et 30 jours<br/>justificatif et lieu obligatoires<br/>statut : EN_ATTENTE_GESTIONNAIRE_RH"]:::op
  R1{{"Demande enregistrée<br/>gestionnaire RH alerté"}}:::re
  E2(["<b>É2</b> — Demande reçue par le gestionnaire RH"]):::ev
  O2["<b>OP2 — Vérifier le dossier</b><hr/>conformité de la demande et du justificatif"]:::op
  E1 --> O1
  O1 --> R1
  R1 --> E2
  E2 --> O2
  R2a{{"Rejetée<br/>motif obligatoire<br/>agent notifié"}}:::ko
  R2b{{"Retournée pour correction<br/>motif obligatoire<br/>agent alerté"}}:::re
  R2c{{"Transmise au niveau de visa choisi<br/>Sous-Directeur ou Directeur"}}:::re
  O2 -->|"non conforme"| R2a
  O2 -->|"incomplet"| R2b
  O2 -->|"conforme"| R2c
  E3(["<b>É3</b> — Demande retournée à l’agent"]):::ev
  O3["<b>OP3 — Corriger et resoumettre</b><hr/>seul le demandeur peut corriger<br/>retour à l’état EN_ATTENTE_GESTIONNAIRE_RH"]:::op
  R2b --> E3
  E3 --> O3
  O3 -->|"nouvelle vérification"| E2
```

### MCT — Permission, cas 1 : 2 jours ou moins (2/2) — visa, décision et notification

Le gestionnaire RH a choisi le niveau de visa (Sous-Directeur ou Directeur). Le refus de visa clôt la demande et prévient le gestionnaire RH ; sinon le DRH tranche. Chaque opération est aussi enregistrée dans l’historique du dossier et dans le journal d’audit.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  S0{{"Transmise au niveau de visa choisi<br/>Sous-Directeur ou Directeur"}}:::re
  E4(["<b>É4</b> — Demande reçue pour visa"]):::ev
  O4["<b>OP4 — Viser la demande</b><hr/>réservé au responsable de la structure de l’agent<br/>motif obligatoire en cas de refus"]:::op
  S0 --> E4
  E4 --> O4
  R4a{{"Visa refusé<br/>demande REJETÉE<br/>gestionnaire RH alerté"}}:::ko
  R4b{{"Visa favorable<br/>statut : EN_ATTENTE_DRH"}}:::re
  O4 -->|"visa défavorable"| R4a
  O4 -->|"visa favorable"| R4b
  E5(["<b>É5</b> — Demande reçue par le DRH"]):::ev
  O5["<b>OP5 — Trancher (décision finale)</b><hr/>validation ou rejet motivé<br/>si validée : solde de l’agent diminué"]:::op
  R4b --> E5
  E5 --> O5
  R5a{{"Demande VALIDÉE<br/>gestionnaire RH alerté"}}:::re
  R5b{{"Demande REJETÉE<br/>motif enregistré<br/>gestionnaire RH alerté"}}:::ko
  O5 -->|"accord"| R5a
  O5 -->|"rejet"| R5b
  E6(["<b>É6</b> — Décision finale à communiquer"]):::ev
  O6["<b>OP6 — Notifier l’agent</b><hr/>effectué par le gestionnaire RH<br/>notification + date de notification"]:::op
  R6{{"Agent notifié<br/>dossier clôturé"}}:::re
  R5a --> E6
  R5b --> E6
  E6 --> O6
  O6 --> R6
```

### MCT — Permission, cas 2 : plus de 2 jours (1/2) — dépôt et vérification

Même début de circuit que le cas 1 ; seule la destination d’une demande conforme change : le gestionnaire RH la transmet directement au DRH.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  E1(["<b>É1</b> — L’agent dépose une demande<br/>(durée &gt; 2 jours)"]):::ev
  O1["<b>OP1 — Enregistrer la demande</b><hr/>durée entre 1 et 30 jours<br/>justificatif et lieu obligatoires<br/>statut : EN_ATTENTE_GESTIONNAIRE_RH"]:::op
  R1{{"Demande enregistrée<br/>gestionnaire RH alerté"}}:::re
  E2(["<b>É2</b> — Demande reçue par le gestionnaire RH"]):::ev
  O2["<b>OP2 — Vérifier le dossier</b><hr/>conformité de la demande et du justificatif"]:::op
  E1 --> O1
  O1 --> R1
  R1 --> E2
  E2 --> O2
  R2a{{"Rejetée<br/>motif obligatoire<br/>agent notifié"}}:::ko
  R2b{{"Retournée pour correction<br/>motif obligatoire<br/>agent alerté"}}:::re
  R2c{{"Transmise directement au DRH<br/>statut : EN_ATTENTE_DRH"}}:::re
  O2 -->|"non conforme"| R2a
  O2 -->|"incomplet"| R2b
  O2 -->|"conforme"| R2c
  E3(["<b>É3</b> — Demande retournée à l’agent"]):::ev
  O3["<b>OP3 — Corriger et resoumettre</b><hr/>seul le demandeur peut corriger<br/>retour à l’état EN_ATTENTE_GESTIONNAIRE_RH"]:::op
  R2b --> E3
  E3 --> O3
  O3 -->|"nouvelle vérification"| E2
```

### MCT — Permission, cas 2 : plus de 2 jours (2/2) — décision et notification

Aucune étape de visa : le DRH tranche directement, puis le gestionnaire RH notifie l’agent.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  S0{{"Transmise directement au DRH<br/>statut : EN_ATTENTE_DRH"}}:::re
  E5(["<b>É4</b> — Demande reçue par le DRH"]):::ev
  O5["<b>OP4 — Trancher (décision finale)</b><hr/>validation ou rejet motivé<br/>si validée : solde de l’agent diminué"]:::op
  S0 --> E5
  E5 --> O5
  R5a{{"Demande VALIDÉE<br/>gestionnaire RH alerté"}}:::re
  R5b{{"Demande REJETÉE<br/>motif enregistré<br/>gestionnaire RH alerté"}}:::ko
  O5 -->|"accord"| R5a
  O5 -->|"rejet"| R5b
  E6(["<b>É5</b> — Décision finale à communiquer"]):::ev
  O6["<b>OP5 — Notifier l’agent</b><hr/>effectué par le gestionnaire RH<br/>notification + date de notification"]:::op
  R6{{"Agent notifié<br/>dossier clôturé"}}:::re
  R5a --> E6
  R5b --> E6
  E6 --> O6
  O6 --> R6
```

### MCT — Déclaration de naissance ou de décès (1/2) — dépôt et contrôle

Un seul circuit pour les deux types. L’agent déclare et joint la pièce officielle ; le gestionnaire RH contrôle. Il ne rejette pas : il retourne le dossier pour correction ou le transmet au DRH.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  E1(["<b>É1</b> — L’agent déclare une naissance ou un décès"]):::ev
  O1["<b>OP1 — Enregistrer la déclaration</b><hr/>pièce obligatoire : extrait d’acte de naissance<br/>ou certificat de décès<br/>statut : EN_ATTENTE_GESTIONNAIRE_RH"]:::op
  R1{{"Déclaration enregistrée<br/>gestionnaire RH alerté"}}:::re
  E2(["<b>É2</b> — Déclaration reçue par le gestionnaire RH"]):::ev
  O2["<b>OP2 — Contrôler les pièces</b><hr/>conformité de l’acte ou du certificat"]:::op
  E1 --> O1
  O1 --> R1
  R1 --> E2
  E2 --> O2
  R2a{{"Retournée pour correction<br/>motif obligatoire<br/>agent alerté"}}:::re
  R2b{{"Transmise au DRH<br/>statut : EN_ATTENTE_RH"}}:::re
  O2 -->|"pièce non conforme"| R2a
  O2 -->|"conforme"| R2b
  E3(["<b>É3</b> — Déclaration retournée"]):::ev
  O3["<b>OP3 — Corriger et resoumettre</b><hr/>seul le déclarant corrige<br/>la pièce officielle reste obligatoire"]:::op
  R2a --> E3
  E3 --> O3
  O3 -->|"nouveau contrôle"| E2
```

### MCT — Déclaration de naissance ou de décès (2/2) — décision et archivage

Le DRH valide ou rejette avec un motif, l’agent est notifié, puis le dossier est archivé.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  S0{{"Transmise au DRH<br/>statut : EN_ATTENTE_RH"}}:::re
  E4(["<b>É4</b> — Déclaration reçue par le DRH"]):::ev
  O4["<b>OP4 — Valider ou rejeter</b><hr/>motif obligatoire en cas de rejet"]:::op
  S0 --> E4
  E4 --> O4
  R4a{{"Déclaration VALIDÉE<br/>agent notifié"}}:::re
  R4b{{"Déclaration REJETÉE<br/>motif communiqué<br/>agent notifié"}}:::ko
  O4 -->|"validation"| R4a
  O4 -->|"rejet"| R4b
  E5(["<b>É5</b> — Dossier traité à clore"]):::ev
  O5["<b>OP5 — Archiver le dossier</b><hr/>réservé au DRH<br/>dossier validé ou rejeté uniquement"]:::op
  R5{{"Dossier ARCHIVÉ<br/>toujours consultable"}}:::re
  R4a --> E5
  R4b --> E5
  E5 --> O5
  O5 --> R5
```

### MCT — Note de service (1/2) — émission et saisie

L’autorité émet la note et la transmet à sa secrétaire, qui la saisit et la met en forme.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  E1(["<b>É1</b> — Une autorité décide d’émettre une note<br/>(DRH, Directeur de Cabinet, Directeur, Sous-Directeur)"]):::ev
  O1["<b>OP1 — Rédiger et transmettre au secrétariat</b><hr/>objet + structures destinataires<br/>numéro de référence attribué<br/>statut : EN_ATTENTE_SAISIE"]:::op
  R1{{"Note transmise<br/>secrétaire alertée"}}:::re
  E2(["<b>É2</b> — Note reçue par la secrétaire"]):::ev
  O2["<b>OP2 — Saisir et mettre en forme</b><hr/>saisie du texte (Word)<br/>statut : EN_ATTENTE_VALIDATION"]:::op
  R2{{"Note saisie<br/>autorité alertée"}}:::re
  E1 --> O1
  O1 --> R1
  R1 --> E2
  E2 --> O2
  O2 --> R2
```

### MCT — Note de service (2/2) — validation, diffusion et archivage

La validation par l’autorité est possible avant la diffusion ; un refus clôt la note. La secrétaire diffuse par email et notification. Le Chef de service ne fait aucune demande : il reçoit seulement les notes diffusées.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  S0{{"Note saisie<br/>autorité alertée"}}:::re
  E3(["<b>É3</b> — Note saisie à valider"]):::ev
  O3["<b>OP3 — Valider ou refuser</b><hr/>seule l’autorité émettrice<br/>motif obligatoire en cas de refus"]:::op
  S0 --> E3
  E3 --> O3
  R3a{{"Note VALIDÉE"}}:::re
  R3b{{"Note REJETÉE<br/>motif enregistré"}}:::ko
  O3 -->|"validation"| R3a
  O3 -->|"refus"| R3b
  E4(["<b>É4</b> — Note saisie ou validée à diffuser"]):::ev
  O4["<b>OP4 — Diffuser aux destinataires</b><hr/>réservé à la secrétaire<br/>notification + email<br/>Directeurs, Sous-Directeurs, Chefs de service, agents"]:::op
  S0 -->|"sans validation"| E4
  R3a --> E4
  E4 --> O4
  R4{{"Note DIFFUSÉE"}}:::re
  O4 --> R4
  E5(["<b>É5</b> — Note à conserver"]):::ev
  O5["<b>OP5 — Archiver</b><hr/>note validée ou diffusée uniquement"]:::op
  R5{{"Note ARCHIVÉE<br/>toujours consultable"}}:::re
  R4 --> E5
  E5 --> O5
  O5 --> R5
```

### MCT — Mot de passe oublié

L’utilisateur ne peut pas se réinitialiser seul : l’administrateur doit d’abord autoriser la demande. Le code de suivi n’est stocké que sous forme hachée et ne sert qu’une fois.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  E1(["<b>É1</b> — Un utilisateur a oublié son mot de passe"]):::ev
  O1["<b>OP1 — Demander une réinitialisation</b><hr/>matricule existant<br/>les demandes précédentes sont annulées<br/>code de suivi généré (affiché une seule fois)"]:::op
  R1{{"Demande EN_ATTENTE<br/>administrateurs alertés"}}:::re
  E2(["<b>É2</b> — Demande reçue par l’administrateur"]):::ev
  O2["<b>OP2 — Autoriser ou refuser</b><hr/>réservé à l’administrateur<br/>l’utilisateur est alerté"]:::op
  E1 --> O1
  O1 --> R1
  R1 --> E2
  E2 --> O2
  R2a{{"Demande AUTORISÉE"}}:::re
  R2b{{"Demande REFUSÉE<br/>fin du circuit"}}:::ko
  O2 -->|"autorisation"| R2a
  O2 -->|"refus"| R2b
  E3(["<b>É3</b> — L’utilisateur choisit un nouveau mot de passe<br/>(matricule + code de suivi)"]):::ev
  O3["<b>OP3 — Réinitialiser le mot de passe</b><hr/>code exact et demande AUTORISÉE<br/>mot de passe de 6 caractères minimum<br/>code à usage unique"]:::op
  R2a --> E3
  E3 --> O3
  R3a{{"Mot de passe changé<br/>sessions fermées<br/>demande UTILISÉE"}}:::re
  R3b{{"Refus<br/>code faux, demande en attente, refusée ou déjà utilisée"}}:::ko
  O3 -->|"conditions remplies"| R3a
  O3 -->|"sinon"| R3b
```

### MCT — Inscription, connexion et déconnexion

Les entrées et sorties sont toutes journalisées, y compris les échecs. L’administrateur peut suspendre un compte : il est alors déconnecté et refusé à la connexion.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart TB
  classDef ev fill:#fff7ed,stroke:#d97706,stroke-width:2px,color:#0f172a
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef re fill:#f1f5f9,stroke:#475569,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  E1(["<b>É1</b> — Une personne demande un compte"]):::ev
  O1["<b>OP1 — Créer le compte</b><hr/>rôle choisi (hors administrateur)<br/>matricule unique<br/>nom et prénom uniques"]:::op
  E1 --> O1
  R1a{{"Compte créé<br/>agent + compte + rôle"}}:::re
  R1b{{"Inscription refusée<br/>matricule ou personne déjà enregistrés"}}:::ko
  O1 -->|"valide"| R1a
  O1 -->|"doublon"| R1b
  E2(["<b>É2</b> — Un utilisateur se présente<br/>(matricule + mot de passe)"]):::ev
  O2["<b>OP2 — Authentifier</b><hr/>identifiants exacts<br/>compte actif<br/>limité à 10 essais par minute"]:::op
  R1a --> E2
  E2 --> O2
  R2a{{"Session ouverte<br/>orientation vers l’espace du rôle<br/>événement CONNEXION journalisé"}}:::re
  R2b{{"Accès refusé<br/>mauvais identifiants ou compte suspendu<br/>événement CONNEXION_REFUSÉE journalisé"}}:::ko
  O2 -->|"accepté"| R2a
  O2 -->|"refusé"| R2b
  E3(["<b>É3</b> — L’utilisateur se déconnecte"]):::ev
  O3["<b>OP3 — Clore la session</b><hr/>jeton d’accès supprimé"]:::op
  R3{{"Session fermée<br/>événement DÉCONNEXION journalisé"}}:::re
  R2a --> E3
  E3 --> O3
  O3 --> R3
```

## 3. Modèles organisationnels des traitements (MOT)

### MOT — Permission, cas 1 : 2 jours ou moins

Postes : Agent, Gestionnaire RH, Sous-Directeur ou Directeur, DRH.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  subgraph L1["Agent"]
    direction TB
    a1["<b>1</b> Déposer la demande"]:::op
    a2["<b>3</b> Corriger et resoumettre"]:::op
  end
  subgraph L2["Gestionnaire RH"]
    direction TB
    r1["<b>2</b> Vérifier le dossier"]:::op
    r6["<b>6</b> Notifier l’agent"]:::op
  end
  subgraph L3["Sous-Directeur ou Directeur"]
    direction TB
    v1["<b>4</b> Viser la demande"]:::op
  end
  subgraph L4["DRH"]
    direction TB
    d1["<b>5</b> Trancher"]:::op
  end
  a1 -->|"demande enregistrée"| r1
  r1 -->|"retour pour correction"| a2
  a2 -->|"resoumise"| r1
  r1 -->|"conforme"| v1
  v1 -->|"visa favorable"| d1
  v1 -->|"visa refusé"| r6
  d1 -->|"décision"| r6
  r6 -->|"notification à l’agent"| a1
```

| N° | Opération | Poste | Nature | Durée | Règles de gestion | Résultat |
|---|---|---|---|---|---|---|
| 1 | Déposer la demande | Agent | Interactif | Immédiate | Durée 1 à 30 jours ; justificatif et lieu obligatoires | Demande EN_ATTENTE_GESTIONNAIRE_RH |
| 2 | Vérifier le dossier | Gestionnaire RH | Manuel puis interactif | Sous 1 jour ouvré | Conforme, incomplet ou non conforme ; motif obligatoire si non conforme ou incomplet; choix du niveau de visa | Transmise, retournée ou rejetée |
| 3 | Corriger et resoumettre | Agent | Interactif | Sous 2 jours | Seul le demandeur ; seulement si retournée | Retour à l’étape 2 |
| 4 | Viser la demande | Sous-Directeur ou Directeur | Manuel puis interactif | Sous 1 jour ouvré | Réservé au responsable de la structure ; motif obligatoire si refus | EN_ATTENTE_DRH ou REJETÉE |
| 5 | Trancher | DRH | Manuel puis interactif | Sous 2 jours ouvrés | Motif obligatoire si rejet ; solde diminué si validée | VALIDÉE ou REJETÉE, gestionnaire RH alerté |
| 6 | Notifier l’agent | Gestionnaire RH | Interactif | Immédiate | Décision finale et agent non encore notifié | Agent notifié, dossier clôturé |

### MOT — Permission, cas 2 : plus de 2 jours

Postes : Agent, Gestionnaire RH, DRH. L’étape de visa n’existe pas.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  subgraph L1["Agent"]
    direction TB
    a1["<b>1</b> Déposer la demande"]:::op
    a2["<b>3</b> Corriger et resoumettre"]:::op
  end
  subgraph L2["Gestionnaire RH"]
    direction TB
    r1["<b>2</b> Vérifier le dossier"]:::op
    r6["<b>5</b> Notifier l’agent"]:::op
  end
  subgraph L4["DRH"]
    direction TB
    d1["<b>4</b> Trancher"]:::op
  end
  a1 -->|"demande enregistrée"| r1
  r1 -->|"retour pour correction"| a2
  a2 -->|"resoumise"| r1
  r1 -->|"conforme"| d1
  d1 -->|"décision"| r6
  r6 -->|"notification à l’agent"| a1
```

| N° | Opération | Poste | Nature | Durée | Règles de gestion | Résultat |
|---|---|---|---|---|---|---|
| 1 | Déposer la demande | Agent | Interactif | Immédiate | Durée 1 à 30 jours ; justificatif et lieu obligatoires | Demande EN_ATTENTE_GESTIONNAIRE_RH |
| 2 | Vérifier le dossier | Gestionnaire RH | Manuel puis interactif | Sous 1 jour ouvré | Conforme, incomplet ou non conforme ; motif obligatoire si non conforme ou incomplet | Transmise, retournée ou rejetée |
| 3 | Corriger et resoumettre | Agent | Interactif | Sous 2 jours | Seul le demandeur ; seulement si retournée | Retour à l’étape 2 |
| 4 | Trancher | DRH | Manuel puis interactif | Sous 2 jours ouvrés | Motif obligatoire si rejet ; solde diminué si validée | VALIDÉE ou REJETÉE, gestionnaire RH alerté |
| 5 | Notifier l’agent | Gestionnaire RH | Interactif | Immédiate | Décision finale et agent non encore notifié | Agent notifié, dossier clôturé |

### MOT — Déclaration de naissance ou de décès

Postes : Agent, Gestionnaire RH, DRH.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  subgraph L1["Agent"]
    direction TB
    a1["<b>1</b> Déclarer"]:::op
    a2["<b>3</b> Corriger et resoumettre"]:::op
  end
  subgraph L2["Gestionnaire RH"]
    direction TB
    r1["<b>2</b> Contrôler les pièces"]:::op
  end
  subgraph L3["DRH"]
    direction TB
    d1["<b>4</b> Valider ou rejeter"]:::op
    d2["<b>5</b> Archiver"]:::op
  end
  a1 -->|"déclaration enregistrée"| r1
  r1 -->|"pièce non conforme"| a2
  a2 -->|"resoumise"| r1
  r1 -->|"conforme"| d1
  d1 -->|"agent notifié"| a1
  d1 -->|"dossier traité"| d2
```

| N° | Opération | Poste | Nature | Durée | Règles de gestion | Résultat |
|---|---|---|---|---|---|---|
| 1 | Déclarer | Agent | Interactif | Immédiate | Pièce officielle obligatoire | EN_ATTENTE_GESTIONNAIRE_RH |
| 2 | Contrôler les pièces | Gestionnaire RH | Manuel puis interactif | Sous 1 jour ouvré | Motif obligatoire si retour | EN_ATTENTE_RH ou RETOUR_CORRECTION |
| 3 | Corriger et resoumettre | Agent | Interactif | Sous 2 jours | Seul le déclarant ; pièce toujours obligatoire | Retour à l’étape 2 |
| 4 | Valider ou rejeter | DRH | Manuel puis interactif | Sous 2 jours ouvrés | Motif obligatoire si rejet | VALIDÉE ou REJETÉE, agent notifié |
| 5 | Archiver | DRH | Interactif | Immédiate | Dossier validé ou rejeté uniquement | ARCHIVÉE |

### MOT — Note de service

Postes : autorité émettrice (DRH, Directeur de Cabinet, Directeur ou Sous-Directeur), secrétaire, destinataires (Directeurs, Sous-Directeurs, Chefs de service, agents).

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  subgraph L1["Autorité émettrice"]
    direction TB
    u1["<b>1</b> Rédiger et transmettre"]:::op
    u3["<b>3</b> Valider ou refuser"]:::op
  end
  subgraph L2["Secrétaire"]
    direction TB
    s1["<b>2</b> Saisir et mettre en forme"]:::op
    s2["<b>4</b> Diffuser"]:::op
    s3["<b>5</b> Archiver"]:::op
  end
  subgraph L3["Destinataires"]
    direction TB
    x1["<b>6</b> Consulter la note"]:::op
  end
  u1 -->|"note transmise"| s1
  s1 -->|"note saisie"| u3
  u3 -->|"validée"| s2
  s1 -->|"sans validation"| s2
  s2 -->|"notification + email"| x1
  s2 -->|"note diffusée"| s3
```

| N° | Opération | Poste | Nature | Durée | Règles de gestion | Résultat |
|---|---|---|---|---|---|---|
| 1 | Rédiger et transmettre | Autorité émettrice | Interactif | Immédiate | Objet et structures destinataires obligatoires | EN_ATTENTE_SAISIE, secrétaire alertée |
| 2 | Saisir et mettre en forme | Secrétaire | Manuel (traitement de texte) | Sous 1 jour | Seulement une note en attente de saisie | EN_ATTENTE_VALIDATION |
| 3 | Valider ou refuser | Autorité émettrice | Interactif | Sous 1 jour | Seule l’autorité émettrice ; motif obligatoire si refus | VALIDÉE ou REJETÉE |
| 4 | Diffuser | Secrétaire | Interactif puis automatique (email) | Immédiate | Note saisie et non refusée | DIFFUSÉE, notifications et emails envoyés |
| 5 | Archiver | Secrétaire ou autorité | Interactif | Immédiate | Note validée ou diffusée | ARCHIVÉE |
| 6 | Consulter la note | Destinataires | Interactif | Libre | Structure concernée ; le Chef de service ne fait aucune demande | Note lue |

### MOT — Mot de passe oublié

Postes : utilisateur, administrateur ; le contrôle final est automatique.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  subgraph L1["Utilisateur"]
    direction TB
    u1["<b>1</b> Demander la réinitialisation"]:::op
    u2["<b>3</b> Choisir un nouveau mot de passe"]:::op
  end
  subgraph L2["Administrateur"]
    direction TB
    m1["<b>2</b> Autoriser ou refuser"]:::op
  end
  subgraph L3["Système"]
    direction TB
    sy1["<b>4</b> Contrôler le code et changer le mot de passe"]:::op
  end
  u1 -->|"demande + alerte"| m1
  m1 -->|"autorisation"| u2
  u2 -->|"matricule + code + nouveau mot de passe"| sy1
  sy1 -->|"connexion possible"| u1
```

| N° | Opération | Poste | Nature | Durée | Règles de gestion | Résultat |
|---|---|---|---|---|---|---|
| 1 | Demander la réinitialisation | Utilisateur | Interactif | Immédiate | Matricule connu ; code de suivi affiché une seule fois | Demande EN_ATTENTE, administrateurs alertés |
| 2 | Autoriser ou refuser | Administrateur | Manuel puis interactif | Sous 1 jour ouvré | Demande encore en attente | AUTORISÉE ou REFUSÉE, utilisateur alerté |
| 3 | Choisir un nouveau mot de passe | Utilisateur | Interactif | Sous quelques jours | Code exact ; 6 caractères minimum | Envoi au contrôle |
| 4 | Contrôler et changer le mot de passe | Système | Automatique | Immédiate | Demande AUTORISÉE ; code à usage unique | Mot de passe changé, sessions fermées |

### MOT — Inscription, connexion et déconnexion

Postes : utilisateur, administrateur ; les traitements A, B et C sont automatiques.

```mermaid
%%{init: {'flowchart': {'htmlLabels': true, 'curve': 'basis'}, 'themeVariables': {'fontSize': '14px'}}}%%
flowchart LR
  classDef op fill:#ecfdf5,stroke:#047857,stroke-width:2px,color:#0f172a
  classDef ko fill:#fef2f2,stroke:#d03b3b,stroke-width:2px,color:#0f172a
  subgraph L1["Utilisateur"]
    direction TB
    u1["<b>1</b> Remplir le formulaire d’inscription"]:::op
    u2["<b>2</b> Saisir matricule et mot de passe"]:::op
    u3["<b>4</b> Se déconnecter"]:::op
  end
  subgraph L2["Système"]
    direction TB
    s1["<b>A</b> Créer le compte"]:::op
    s2["<b>B</b> Authentifier et journaliser"]:::op
    s3["<b>C</b> Clore la session et journaliser"]:::op
  end
  subgraph L3["Administrateur"]
    direction TB
    m1["<b>3</b> Suspendre ou réactiver un compte"]:::op
  end
  u1 -->|"données saisies"| s1
  s1 -->|"compte créé"| u2
  u2 -->|"identifiants"| s2
  s2 -->|"session ouverte"| u3
  u3 -->|"demande de sortie"| s3
  m1 -->|"compte suspendu = refus"| s2
```

| N° | Opération | Poste | Nature | Durée | Règles de gestion | Résultat |
|---|---|---|---|---|---|---|
| 1 | Remplir le formulaire d’inscription | Utilisateur | Interactif | Immédiate | Rôle choisi hors administrateur | Demande d’inscription |
| A | Créer le compte | Système | Automatique | Immédiate | Matricule et personne uniques | Compte créé ou refus |
| 2 | Saisir matricule et mot de passe | Utilisateur | Interactif | Immédiate | 10 essais par minute au plus | Envoi à l’authentification |
| B | Authentifier et journaliser | Système | Automatique | Immédiate | Identifiants exacts ; compte actif | Session ouverte ou refus, événement journalisé |
| 3 | Suspendre ou réactiver un compte | Administrateur | Interactif | À la demande | Impossible sur son propre compte | Compte suspendu (déconnecté) ou réactivé |
| 4 | Se déconnecter | Utilisateur | Interactif | Immédiate | — | Demande de sortie |
| C | Clore la session et journaliser | Système | Automatique | Immédiate | — | Jeton supprimé, événement journalisé |

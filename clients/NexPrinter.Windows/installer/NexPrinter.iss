#define MyAppName "NEX Printer"
#define MyAppPublisher "NEX"
#define MyAppExeName "NexPrinter.exe"
#define MyAppId "{{A3AC5352-1DB0-4BE2-B9CF-EEB0952E71D9}"
#define MyAppVersion GetEnv("NEX_PRINTER_VERSION")
#define SourceDirectory GetEnv("NEX_PRINTER_SOURCE_DIR")
#define OutputDirectory GetEnv("NEX_PRINTER_OUTPUT_DIR")

#if MyAppVersion == ""
  #error NEX_PRINTER_VERSION is required
#endif
#if SourceDirectory == ""
  #error NEX_PRINTER_SOURCE_DIR is required
#endif
#if OutputDirectory == ""
  #error NEX_PRINTER_OUTPUT_DIR is required
#endif

[Setup]
AppId={#MyAppId}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
VersionInfoVersion={#MyAppVersion}
DefaultDirName={autopf}\NEX Printer
DefaultGroupName=NEX Printer
DisableProgramGroupPage=yes
OutputDir={#OutputDirectory}
OutputBaseFilename=NEX-Printer-Setup-{#MyAppVersion}-x64
SetupIconFile=..\src\NexPrinter.App\Assets\NexPrinter.ico
UninstallDisplayIcon={app}\{#MyAppExeName}
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=admin
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
CloseApplications=yes
RestartApplications=no
AppMutex=Local\NEXPrinter.Client
MinVersion=10.0.17763
UsePreviousAppDir=yes
UsePreviousTasks=yes
SetupLogging=yes

[Languages]
Name: "polish"; MessagesFile: "compiler:Languages\Polish.isl"

[Tasks]
Name: "autostart"; Description: "Uruchamiaj NEX Printer razem z Windows"; GroupDescription: "Dodatkowe ustawienia:"; Flags: checkedonce
Name: "desktopicon"; Description: "Ikona NEX Printer na pulpicie"; GroupDescription: "Dodatkowe ustawienia:"; Flags: unchecked

[Files]
Source: "{#SourceDirectory}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\NEX Printer"; Filename: "{app}\{#MyAppExeName}"
Name: "{autodesktop}\NEX Printer"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Uruchom NEX Printer"; Flags: nowait postinstall skipifsilent runasoriginaluser

[UninstallRun]
Filename: "{sys}\reg.exe"; Parameters: "delete ""HKCU\Software\Microsoft\Windows\CurrentVersion\Run"" /v ""NEX Printer"" /f"; Flags: runhidden; RunOnceId: "RemoveNexPrinterAutostart"

[Code]
const
  WebView2ClientKey = 'Software\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}';

function WebView2Installed(): Boolean;
var
  Version: String;
begin
  Result :=
    RegQueryStringValue(HKLM32, WebView2ClientKey, 'pv', Version) or
    RegQueryStringValue(HKLM64, WebView2ClientKey, 'pv', Version) or
    RegQueryStringValue(HKCU, WebView2ClientKey, 'pv', Version);
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  InitialAutostartPreference: String;
begin
  if CurStep = ssPostInstall then
  begin
    if WizardIsTaskSelected('autostart') then
      InitialAutostartPreference := 'enabled'
    else
      InitialAutostartPreference := 'disabled';

    SaveStringToFile(
      ExpandConstant('{app}\initial-autostart.preference'),
      InitialAutostartPreference,
      False);

    if not WebView2Installed() then
    begin
      MsgBox(
        'Brak Microsoft Edge WebView2 Runtime. NEX Printer wymaga tego skladnika do drukowania PDF.',
        mbInformation,
        MB_OK);
    end;
  end;
end;

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="x-apple-disable-message-reformatting" />
  <title>{{ $subject ?? 'Corporate Update' }}</title>
  <!--[if mso]>
  <style type="text/css">
    body, table, td, p, a, span { font-family: Arial, sans-serif !important; }
  </style>
  <![endif]-->
  <style type="text/css">
    body, p, h1, h2, h3, h4, ul, ol, li, table, td { margin: 0; padding: 0; }
    body {
      width: 100% !important;
      height: 100% !important;
      background-color: #f3f4f6;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      -webkit-text-size-adjust: 100%;
      -ms-text-size-adjust: 100%;
    }
    table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; display: block; }
    a { text-decoration: none; }
    ul { margin: 0 0 24px 0; padding-left: 18px; }
    li { margin-bottom: 7px; }
    @media only screen and (max-width: 620px) {
      .email-wrapper { width: 100% !important; max-width: 100% !important; }
      .header-col-left, .header-col-right { display: block !important; width: 100% !important; text-align: left !important; }
      .header-col-right { padding-top: 14px !important; }
      .nav-item { padding: 0 8px !important; font-size: 11px !important; }
      .body-content-pad { padding: 24px 18px !important; }
      .footer-pad { padding: 24px 18px !important; }
    }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; -webkit-font-smoothing: antialiased;">

  <table width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#f3f4f6" role="presentation" style="background-color: #f3f4f6; padding: 30px 10px;">
    <tr>
      <td align="center">

        <!--[if (gte mso 9)|(IE)]>
        <table width="600" align="center" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td>
        <![endif]-->
        <table class="email-wrapper" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#ffffff" role="presentation" style="max-width: 600px; width: 100%; background-color: #ffffff; margin: 0 auto; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border: 1px solid #e5e7eb;">

          <!-- 1. HEADER -->
          <tr>
            <td style="padding: 24px 32px; background-color: #ffffff;">
              <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                  <td class="header-col-left" valign="middle" align="left">
                    <a href="{{ $companyUrl ?? 'https://www.andlyl.com' }}" target="_blank" style="text-decoration: none; display: inline-block;">
                      @if(!empty($logoUrl))
                        <img src="{{ $logoUrl }}" alt="{{ $companyName ?? 'Logo' }}" width="130" style="display:block; border:0; width:130px; height:auto;" />
                      @else
                        <table border="0" cellpadding="0" cellspacing="0" role="presentation">
                          <tr>
                            <td valign="middle" style="padding-right: 8px;">
                              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#111827" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display: block;">
                                <line x1="12" y1="22" x2="12" y2="2"></line>
                                <path d="M5 6l7-4 7 4"></path>
                                <path d="M7 10l5-3 5 3"></path>
                                <circle cx="12" cy="14" r="1.5" fill="#111827"></circle>
                              </svg>
                            </td>
                            <td valign="middle">
                              <span style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 24px; font-weight: 800; color: #111827; letter-spacing: -0.5px;">{{ $companyName ?? 'Andlyl' }}</span>
                            </td>
                          </tr>
                        </table>
                      @endif
                    </a>
                  </td>
                  <td class="header-col-right" valign="middle" align="right" style="text-align: right;">
                    <p style="margin: 0; font-size: 10px; font-weight: 700; color: #6b7280; letter-spacing: 0.8px; text-transform: uppercase;">TALK TO US</p>
                    <p style="margin: 2px 0 0 0; font-size: 14px; font-weight: 800; color: #111827;">
                      <a href="tel:{{ $phoneRaw ?? '+17183953598' }}" style="color: #111827; text-decoration: none;">{{ $phone ?? '+1 718 395 3598' }}</a>
                    </p>
                    <p style="margin: 2px 0 0 0; font-size: 12px; font-weight: 500; color: #111827;">
                      <a href="mailto:{{ $supportEmail ?? 'hello@andlyl.com' }}" style="color: #111827; text-decoration: none;">{{ $supportEmail ?? 'hello@andlyl.com' }}</a>
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- 2. NAVBAR -->
          <tr>
            <td bgcolor="#000000" style="background-color: #000000; border-bottom: 3px solid #ccff00; padding: 10px 16px;">
              <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                  <td align="center" style="text-align: center;">
                    <a href="{{ $companyUrl ?? 'https://www.andlyl.com' }}/company" target="_blank" class="nav-item" style="color: #ffffff; font-size: 11.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; text-decoration: none; padding: 0 12px; display: inline-block;">COMPANY</a>
                    <a href="{{ $companyUrl ?? 'https://www.andlyl.com' }}/services" target="_blank" class="nav-item" style="color: #ffffff; font-size: 11.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; text-decoration: none; padding: 0 12px; display: inline-block;">SERVICES</a>
                    <a href="{{ $companyUrl ?? 'https://www.andlyl.com' }}/projects" target="_blank" class="nav-item" style="color: #ffffff; font-size: 11.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; text-decoration: none; padding: 0 12px; display: inline-block;">PROJECTS</a>
                    <a href="{{ $companyUrl ?? 'https://www.andlyl.com' }}/contact" target="_blank" class="nav-item" style="color: #ccff00; font-size: 11.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; text-decoration: none; padding: 0 12px; display: inline-block;">CONTACT</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- 3. BODY -->
          <tr>
            <td class="body-content-pad" style="padding: 36px 36px 28px 36px; background-color: #ffffff; color: #1f2937; font-size: 13.5px; line-height: 1.6;">

              <p style="margin: 0 0 18px 0; font-size: 14px; font-weight: 700; color: #111827;">Hi {{ $recipientName ?? 'Atique' }},</p>

              @if(isset($bodyContent))
                {!! $bodyContent !!}
              @else
                <p style="margin: 0 0 24px 0; color: #374151; line-height: 1.65;">
                  Thank you for the updates. The email icons are working now, thank you. We checked the website and need the following:
                </p>

                <!-- Section 1 -->
                <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 16px 0;">
                  <tr>
                    <td width="3.5" bgcolor="#ccff00" style="background-color: #ccff00; width: 3.5px; font-size: 0; line-height: 0;">&nbsp;</td>
                    <td style="padding-left: 9px;">
                      <h2 style="margin: 0; font-size: 15px; font-weight: 800; color: #111827; letter-spacing: -0.2px;">1. Case studies (Projects page)</h2>
                    </td>
                  </tr>
                </table>

                <p style="margin: 0 0 12px 0; color: #374151; line-height: 1.65;">
                  On the project pages we can only see a short overview, the image boards and some figures. The full case study is not showing. We have attached a Word file (<strong>Andlyl Case Studies Website Copy.docx</strong>) with the complete case study content for all 9 projects.
                </p>

                <ul style="margin: 0 0 26px 0; padding-left: 18px; color: #374151; line-height: 1.65;">
                  <li style="margin-bottom: 7px;">Please replace the current case study text on each project page and pop-up with the content from this file, and keep the existing image boards.</li>
                  <li style="margin-bottom: 7px;">Please do not add any results figures, timelines or tech stacks that are not in the file.</li>
                  <li style="margin-bottom: 7px;">Doplin: please replace the current description with the Doplin copy in the file.</li>
                  <li style="margin-bottom: 7px;">Please also add the short Projects page introduction and the call-to-action box from the file.</li>
                </ul>

                <!-- Section 2 -->
                <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 16px 0;">
                  <tr>
                    <td width="3.5" bgcolor="#ccff00" style="background-color: #ccff00; width: 3.5px; font-size: 0; line-height: 0;">&nbsp;</td>
                    <td style="padding-left: 9px;">
                      <h2 style="margin: 0; font-size: 15px; font-weight: 800; color: #111827; letter-spacing: -0.2px;">2. Web samples page</h2>
                    </td>
                  </tr>
                </table>

                <ul style="margin: 0 0 26px 0; padding-left: 18px; color: #374151; line-height: 1.65;">
                  <li style="margin-bottom: 7px;">The &ldquo;Visit Live Demo&rdquo; button does not open anything. Please add a working live link for each sample.</li>
                  <li style="margin-bottom: 7px;">Please replace the placeholder text in the preview section (&ldquo;Project Title&rdquo;, &ldquo;Theme Tagline&rdquo;, &ldquo;Detailed relatable content...&rdquo;).</li>
                </ul>

                <!-- Section 3 -->
                <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 16px 0;">
                  <tr>
                    <td width="3.5" bgcolor="#ccff00" style="background-color: #ccff00; width: 3.5px; font-size: 0; line-height: 0;">&nbsp;</td>
                    <td style="padding-left: 9px;">
                      <h2 style="margin: 0; font-size: 15px; font-weight: 800; color: #111827; letter-spacing: -0.2px;">3. Sample builds and AI bots (now assigned to you)</h2>
                    </td>
                  </tr>
                </table>

                <p style="margin: 0 0 12px 0; color: #374151; line-height: 1.65;">
                  The tasks we had planned for Mark are now yours:
                </p>

                <ul style="margin: 0 0 24px 0; padding-left: 18px; color: #374151; line-height: 1.65;">
                  <li style="margin-bottom: 7px;">Build the remaining home-service sample websites, starting with an HVAC sample and a cleaning-service sample.</li>
                  <li style="margin-bottom: 7px;">Build the sample AI bots: booking bot and real-estate lead bot.</li>
                  <li style="margin-bottom: 7px;">Set up the AI receptionist demo with a phone number prospects can call, once the server is active again.</li>
                </ul>

                <p style="margin: 0 0 16px 0; color: #374151; line-height: 1.65;">
                  Please reply with a completion date for each point.
                </p>

                <p style="margin: 0 0 28px 0; color: #374151;">
                  Thanks,
                </p>
              @endif

              <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                  <td style="border-top: 1px solid #e5e7eb; height: 1px; line-height: 1px; font-size: 0;">&nbsp;</td>
                </tr>
              </table>

              <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin-top: 24px;">
                <tr>
                  <td>
                    <p style="margin: 0 0 3px 0; font-size: 14px; font-weight: 800; color: #111827;">{{ $senderDept ?? 'Operations Department' }}</p>
                    <p style="margin: 0 0 3px 0; font-size: 13px; color: #4b5563;">{{ $companyName ?? 'Andlyl Corp' }}</p>
                    <p style="margin: 0 0 3px 0; font-size: 13px; color: #4b5563;">
                      <a href="tel:{{ $phoneRaw ?? '+17183953598' }}" style="color: #4b5563; text-decoration: none;">{{ $phone ?? '+1 718 395 3598' }}</a>
                    </p>
                    <p style="margin: 0; font-size: 13px; color: #4b5563;">
                      <a href="mailto:{{ $supportEmail ?? 'hello@andlyl.com' }}" style="color: #4b5563; text-decoration: none;">{{ $supportEmail ?? 'hello@andlyl.com' }}</a>
                    </p>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- 4. FOOTER -->
          <tr>
            <td class="footer-pad" bgcolor="#000000" style="background-color: #000000; padding: 30px 36px 34px 36px;">

              <p style="margin: 0 0 16px 0; font-size: 11px; font-weight: 800; color: #ccff00; letter-spacing: 1px; text-transform: uppercase;">
                FOLLOW {{ strtoupper($companyName ?? 'ANDLYL') }}
              </p>

              <table border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 24px 0;">
                <tr>
                  <!-- LinkedIn -->
                  <td align="center" valign="middle" style="padding-right: 12px;">
                    <a href="{{ $socialLinkedin ?? 'https://linkedin.com' }}" target="_blank" style="text-decoration: none; display: inline-block;">
                      <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="32" height="32" bgcolor="#ccff00" style="background-color: #ccff00; width: 32px; height: 32px; border-radius: 50%; text-align: center;">
                        <tr>
                          <td align="center" valign="middle" style="vertical-align: middle; text-align: center; line-height: 1;">
                            <span style="font-family: Arial, sans-serif; font-size: 14px; font-weight: 900; color: #000000; line-height: 1;">in</span>
                          </td>
                        </tr>
                      </table>
                    </a>
                  </td>

                  <!-- Facebook -->
                  <td align="center" valign="middle" style="padding-right: 12px;">
                    <a href="{{ $socialFacebook ?? 'https://facebook.com' }}" target="_blank" style="text-decoration: none; display: inline-block;">
                      <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="32" height="32" bgcolor="#ccff00" style="background-color: #ccff00; width: 32px; height: 32px; border-radius: 50%; text-align: center;">
                        <tr>
                          <td align="center" valign="middle" style="vertical-align: middle; text-align: center; line-height: 1;">
                            <span style="font-family: Arial, sans-serif; font-size: 15px; font-weight: 900; color: #000000; line-height: 1;">f</span>
                          </td>
                        </tr>
                      </table>
                    </a>
                  </td>

                  <!-- Instagram -->
                  <td align="center" valign="middle" style="padding-right: 12px;">
                    <a href="{{ $socialInstagram ?? 'https://instagram.com' }}" target="_blank" style="text-decoration: none; display: inline-block;">
                      <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="32" height="32" bgcolor="#ccff00" style="background-color: #ccff00; width: 32px; height: 32px; border-radius: 50%; text-align: center;">
                        <tr>
                          <td align="center" valign="middle" style="vertical-align: middle; text-align: center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#000000" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" style="display: block; margin: 0 auto;">
                              <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
                              <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                              <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                            </svg>
                          </td>
                        </tr>
                      </table>
                    </a>
                  </td>

                  <!-- X (Twitter) -->
                  <td align="center" valign="middle">
                    <a href="{{ $socialTwitter ?? 'https://x.com' }}" target="_blank" style="text-decoration: none; display: inline-block;">
                      <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="32" height="32" bgcolor="#ccff00" style="background-color: #ccff00; width: 32px; height: 32px; border-radius: 50%; text-align: center;">
                        <tr>
                          <td align="center" valign="middle" style="vertical-align: middle; text-align: center; line-height: 1;">
                            <span style="font-family: Arial, sans-serif; font-size: 14px; font-weight: 900; color: #000000; line-height: 1;">X</span>
                          </td>
                        </tr>
                      </table>
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin: 0 0 4px 0; font-size: 12.5px; font-weight: 800; color: #ccff00; letter-spacing: 0.2px;">
                {{ $companyNameFull ?? 'Andlyl Corp' }}
              </p>
              <p style="margin: 0 0 4px 0; font-size: 12px; color: #d1d5db; line-height: 1.5;">
                {{ $companyAddress ?? '19904 Hillside Ave, Hollis, NY 11423, USA' }}
              </p>
              <p style="margin: 0; font-size: 12px;">
                <a href="{{ $companyUrl ?? 'https://www.andlyl.com' }}" target="_blank" style="color: #ffffff; text-decoration: underline; font-weight: 500;">{{ $companyDomain ?? 'www.andlyl.com' }}</a>
              </p>

            </td>
          </tr>

        </table>
        <!--[if (gte mso 9)|(IE)]>
            </td>
          </tr>
        </table>
        <![endif]-->

      </td>
    </tr>
  </table>

</body>
</html>


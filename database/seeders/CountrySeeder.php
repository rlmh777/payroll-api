<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Country;
use Illuminate\Support\Str;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Fallback to static data if API fails - Complete list of all countries
        $countries = [
            ['name' => 'Afghanistan', 'code1' => 'AF', 'code2' => 'AFG', 'nationalityName' => 'Afghan'],
            ['name' => 'Albania', 'code1' => 'AL', 'code2' => 'ALB', 'nationalityName' => 'Albanian'],
            ['name' => 'Algeria', 'code1' => 'DZ', 'code2' => 'DZA', 'nationalityName' => 'Algerian'],
            ['name' => 'Andorra', 'code1' => 'AD', 'code2' => 'AND', 'nationalityName' => 'Andorran'],
            ['name' => 'Angola', 'code1' => 'AO', 'code2' => 'AGO', 'nationalityName' => 'Angolan'],
            ['name' => 'Antigua and Barbuda', 'code1' => 'AG', 'code2' => 'ATG', 'nationalityName' => 'Antiguan'],
            ['name' => 'Argentina', 'code1' => 'AR', 'code2' => 'ARG', 'nationalityName' => 'Argentine'],
            ['name' => 'Armenia', 'code1' => 'AM', 'code2' => 'ARM', 'nationalityName' => 'Armenian'],
            ['name' => 'Australia', 'code1' => 'AU', 'code2' => 'AUS', 'nationalityName' => 'Australian'],
            ['name' => 'Austria', 'code1' => 'AT', 'code2' => 'AUT', 'nationalityName' => 'Austrian'],
            ['name' => 'Azerbaijan', 'code1' => 'AZ', 'code2' => 'AZE', 'nationalityName' => 'Azerbaijani'],
            ['name' => 'Bahamas', 'code1' => 'BS', 'code2' => 'BHS', 'nationalityName' => 'Bahamian'],
            ['name' => 'Bahrain', 'code1' => 'BH', 'code2' => 'BHR', 'nationalityName' => 'Bahraini'],
            ['name' => 'Bangladesh', 'code1' => 'BD', 'code2' => 'BGD', 'nationalityName' => 'Bangladeshi'],
            ['name' => 'Barbados', 'code1' => 'BB', 'code2' => 'BRB', 'nationalityName' => 'Barbadian'],
            ['name' => 'Belarus', 'code1' => 'BY', 'code2' => 'BLR', 'nationalityName' => 'Belarusian'],
            ['name' => 'Belgium', 'code1' => 'BE', 'code2' => 'BEL', 'nationalityName' => 'Belgian'],
            ['name' => 'Belize', 'code1' => 'BZ', 'code2' => 'BLZ', 'nationalityName' => 'Belizean'],
            ['name' => 'Benin', 'code1' => 'BJ', 'code2' => 'BEN', 'nationalityName' => 'Beninese'],
            ['name' => 'Bhutan', 'code1' => 'BT', 'code2' => 'BTN', 'nationalityName' => 'Bhutanese'],
            ['name' => 'Bolivia', 'code1' => 'BO', 'code2' => 'BOL', 'nationalityName' => 'Bolivian'],
            ['name' => 'Bosnia and Herzegovina', 'code1' => 'BA', 'code2' => 'BIH', 'nationalityName' => 'Bosnian'],
            ['name' => 'Botswana', 'code1' => 'BW', 'code2' => 'BWA', 'nationalityName' => 'Motswana'],
            ['name' => 'Brazil', 'code1' => 'BR', 'code2' => 'BRA', 'nationalityName' => 'Brazilian'],
            ['name' => 'Brunei', 'code1' => 'BN', 'code2' => 'BRN', 'nationalityName' => 'Bruneian'],
            ['name' => 'Bulgaria', 'code1' => 'BG', 'code2' => 'BGR', 'nationalityName' => 'Bulgarian'],
            ['name' => 'Burkina Faso', 'code1' => 'BF', 'code2' => 'BFA', 'nationalityName' => 'Burkinabe'],
            ['name' => 'Burundi', 'code1' => 'BI', 'code2' => 'BDI', 'nationalityName' => 'Burundian'],
            ['name' => 'Cambodia', 'code1' => 'KH', 'code2' => 'KHM', 'nationalityName' => 'Cambodian'],
            ['name' => 'Cameroon', 'code1' => 'CM', 'code2' => 'CMR', 'nationalityName' => 'Cameroonian'],
            ['name' => 'Canada', 'code1' => 'CA', 'code2' => 'CAN', 'nationalityName' => 'Canadian'],
            ['name' => 'Cape Verde', 'code1' => 'CV', 'code2' => 'CPV', 'nationalityName' => 'Cape Verdean'],
            ['name' => 'Central African Republic', 'code1' => 'CF', 'code2' => 'CAF', 'nationalityName' => 'Central African'],
            ['name' => 'Chad', 'code1' => 'TD', 'code2' => 'TCD', 'nationalityName' => 'Chadian'],
            ['name' => 'Chile', 'code1' => 'CL', 'code2' => 'CHL', 'nationalityName' => 'Chilean'],
            ['name' => 'China', 'code1' => 'CN', 'code2' => 'CHN', 'nationalityName' => 'Chinese'],
            ['name' => 'Colombia', 'code1' => 'CO', 'code2' => 'COL', 'nationalityName' => 'Colombian'],
            ['name' => 'Comoros', 'code1' => 'KM', 'code2' => 'COM', 'nationalityName' => 'Comoran'],
            ['name' => 'Congo', 'code1' => 'CG', 'code2' => 'COG', 'nationalityName' => 'Congolese'],
            ['name' => 'Costa Rica', 'code1' => 'CR', 'code2' => 'CRI', 'nationalityName' => 'Costa Rican'],
            ['name' => 'Croatia', 'code1' => 'HR', 'code2' => 'HRV', 'nationalityName' => 'Croatian'],
            ['name' => 'Cuba', 'code1' => 'CU', 'code2' => 'CUB', 'nationalityName' => 'Cuban'],
            ['name' => 'Cyprus', 'code1' => 'CY', 'code2' => 'CYP', 'nationalityName' => 'Cypriot'],
            ['name' => 'Czech Republic', 'code1' => 'CZ', 'code2' => 'CZE', 'nationalityName' => 'Czech'],
            ['name' => 'Denmark', 'code1' => 'DK', 'code2' => 'DNK', 'nationalityName' => 'Danish'],
            ['name' => 'Djibouti', 'code1' => 'DJ', 'code2' => 'DJI', 'nationalityName' => 'Djiboutian'],
            ['name' => 'Dominica', 'code1' => 'DM', 'code2' => 'DMA', 'nationalityName' => 'Dominican'],
            ['name' => 'Dominican Republic', 'code1' => 'DO', 'code2' => 'DOM', 'nationalityName' => 'Dominican'],
            ['name' => 'DR Congo', 'code1' => 'CD', 'code2' => 'COD', 'nationalityName' => 'Congolese'],
            ['name' => 'Ecuador', 'code1' => 'EC', 'code2' => 'ECU', 'nationalityName' => 'Ecuadorean'],
            ['name' => 'Egypt', 'code1' => 'EG', 'code2' => 'EGY', 'nationalityName' => 'Egyptian'],
            ['name' => 'El Salvador', 'code1' => 'SV', 'code2' => 'SLV', 'nationalityName' => 'Salvadoran'],
            ['name' => 'Equatorial Guinea', 'code1' => 'GQ', 'code2' => 'GNQ', 'nationalityName' => 'Equatorial Guinean'],
            ['name' => 'Eritrea', 'code1' => 'ER', 'code2' => 'ERI', 'nationalityName' => 'Eritrean'],
            ['name' => 'Estonia', 'code1' => 'EE', 'code2' => 'EST', 'nationalityName' => 'Estonian'],
            ['name' => 'Eswatini', 'code1' => 'SZ', 'code2' => 'SWZ', 'nationalityName' => 'Swazi'],
            ['name' => 'Ethiopia', 'code1' => 'ET', 'code2' => 'ETH', 'nationalityName' => 'Ethiopian'],
            ['name' => 'Fiji', 'code1' => 'FJ', 'code2' => 'FJI', 'nationalityName' => 'Fijian'],
            ['name' => 'Finland', 'code1' => 'FI', 'code2' => 'FIN', 'nationalityName' => 'Finnish'],
            ['name' => 'France', 'code1' => 'FR', 'code2' => 'FRA', 'nationalityName' => 'French'],
            ['name' => 'Gabon', 'code1' => 'GA', 'code2' => 'GAB', 'nationalityName' => 'Gabonese'],
            ['name' => 'Gambia', 'code1' => 'GM', 'code2' => 'GMB', 'nationalityName' => 'Gambian'],
            ['name' => 'Georgia', 'code1' => 'GE', 'code2' => 'GEO', 'nationalityName' => 'Georgian'],
            ['name' => 'Germany', 'code1' => 'DE', 'code2' => 'DEU', 'nationalityName' => 'German'],
            ['name' => 'Ghana', 'code1' => 'GH', 'code2' => 'GHA', 'nationalityName' => 'Ghanaian'],
            ['name' => 'Greece', 'code1' => 'GR', 'code2' => 'GRC', 'nationalityName' => 'Greek'],
            ['name' => 'Grenada', 'code1' => 'GD', 'code2' => 'GRD', 'nationalityName' => 'Grenadian'],
            ['name' => 'Guatemala', 'code1' => 'GT', 'code2' => 'GTM', 'nationalityName' => 'Guatemalan'],
            ['name' => 'Guinea', 'code1' => 'GN', 'code2' => 'GIN', 'nationalityName' => 'Guinean'],
            ['name' => 'Guinea-Bissau', 'code1' => 'GW', 'code2' => 'GNB', 'nationalityName' => 'Guinean'],
            ['name' => 'Guyana', 'code1' => 'GY', 'code2' => 'GUY', 'nationalityName' => 'Guyanese'],
            ['name' => 'Haiti', 'code1' => 'HT', 'code2' => 'HTI', 'nationalityName' => 'Haitian'],
            ['name' => 'Honduras', 'code1' => 'HN', 'code2' => 'HND', 'nationalityName' => 'Honduran'],
            ['name' => 'Hungary', 'code1' => 'HU', 'code2' => 'HUN', 'nationalityName' => 'Hungarian'],
            ['name' => 'Iceland', 'code1' => 'IS', 'code2' => 'ISL', 'nationalityName' => 'Icelandic'],
            ['name' => 'India', 'code1' => 'IN', 'code2' => 'IND', 'nationalityName' => 'Indian'],
            ['name' => 'Indonesia', 'code1' => 'ID', 'code2' => 'IDN', 'nationalityName' => 'Indonesian'],
            ['name' => 'Iran', 'code1' => 'IR', 'code2' => 'IRN', 'nationalityName' => 'Iranian'],
            ['name' => 'Iraq', 'code1' => 'IQ', 'code2' => 'IRQ', 'nationalityName' => 'Iraqi'],
            ['name' => 'Ireland', 'code1' => 'IE', 'code2' => 'IRL', 'nationalityName' => 'Irish'],
            ['name' => 'Israel', 'code1' => 'IL', 'code2' => 'ISR', 'nationalityName' => 'Israeli'],
            ['name' => 'Italy', 'code1' => 'IT', 'code2' => 'ITA', 'nationalityName' => 'Italian'],
            ['name' => 'Jamaica', 'code1' => 'JM', 'code2' => 'JAM', 'nationalityName' => 'Jamaican'],
            ['name' => 'Japan', 'code1' => 'JP', 'code2' => 'JPN', 'nationalityName' => 'Japanese'],
            ['name' => 'Jordan', 'code1' => 'JO', 'code2' => 'JOR', 'nationalityName' => 'Jordanian'],
            ['name' => 'Kazakhstan', 'code1' => 'KZ', 'code2' => 'KAZ', 'nationalityName' => 'Kazakhstani'],
            ['name' => 'Kenya', 'code1' => 'KE', 'code2' => 'KEN', 'nationalityName' => 'Kenyan'],
            ['name' => 'Kiribati', 'code1' => 'KI', 'code2' => 'KIR', 'nationalityName' => 'I-Kiribati'],
            ['name' => 'Kosovo', 'code1' => 'XK', 'code2' => 'XKK', 'nationalityName' => 'Kosovar'],
            ['name' => 'Kuwait', 'code1' => 'KW', 'code2' => 'KWT', 'nationalityName' => 'Kuwaiti'],
            ['name' => 'Kyrgyzstan', 'code1' => 'KG', 'code2' => 'KGZ', 'nationalityName' => 'Kyrgyzstani'],
            ['name' => 'Laos', 'code1' => 'LA', 'code2' => 'LAO', 'nationalityName' => 'Laotian'],
            ['name' => 'Latvia', 'code1' => 'LV', 'code2' => 'LVA', 'nationalityName' => 'Latvian'],
            ['name' => 'Lebanon', 'code1' => 'LB', 'code2' => 'LBN', 'nationalityName' => 'Lebanese'],
            ['name' => 'Lesotho', 'code1' => 'LS', 'code2' => 'LSO', 'nationalityName' => 'Mosotho'],
            ['name' => 'Liberia', 'code1' => 'LR', 'code2' => 'LBR', 'nationalityName' => 'Liberian'],
            ['name' => 'Libya', 'code1' => 'LY', 'code2' => 'LBY', 'nationalityName' => 'Libyan'],
            ['name' => 'Liechtenstein', 'code1' => 'LI', 'code2' => 'LIE', 'nationalityName' => 'Liechtensteiner'],
            ['name' => 'Lithuania', 'code1' => 'LT', 'code2' => 'LTU', 'nationalityName' => 'Lithuanian'],
            ['name' => 'Luxembourg', 'code1' => 'LU', 'code2' => 'LUX', 'nationalityName' => 'Luxembourgish'],
            ['name' => 'Madagascar', 'code1' => 'MG', 'code2' => 'MDG', 'nationalityName' => 'Malagasy'],
            ['name' => 'Malawi', 'code1' => 'MW', 'code2' => 'MWI', 'nationalityName' => 'Malawian'],
            ['name' => 'Malaysia', 'code1' => 'MY', 'code2' => 'MYS', 'nationalityName' => 'Malaysian'],
            ['name' => 'Maldives', 'code1' => 'MV', 'code2' => 'MDV', 'nationalityName' => 'Maldivian'],
            ['name' => 'Mali', 'code1' => 'ML', 'code2' => 'MLI', 'nationalityName' => 'Malian'],
            ['name' => 'Malta', 'code1' => 'MT', 'code2' => 'MLT', 'nationalityName' => 'Maltese'],
            ['name' => 'Marshall Islands', 'code1' => 'MH', 'code2' => 'MHL', 'nationalityName' => 'Marshallese'],
            ['name' => 'Mauritania', 'code1' => 'MR', 'code2' => 'MRT', 'nationalityName' => 'Mauritanian'],
            ['name' => 'Mauritius', 'code1' => 'MU', 'code2' => 'MUS', 'nationalityName' => 'Mauritian'],
            ['name' => 'Mexico', 'code1' => 'MX', 'code2' => 'MEX', 'nationalityName' => 'Mexican'],
            ['name' => 'Micronesia', 'code1' => 'FM', 'code2' => 'FSM', 'nationalityName' => 'Micronesian'],
            ['name' => 'Moldova', 'code1' => 'MD', 'code2' => 'MDA', 'nationalityName' => 'Moldovan'],
            ['name' => 'Monaco', 'code1' => 'MC', 'code2' => 'MCO', 'nationalityName' => 'Monacan'],
            ['name' => 'Mongolia', 'code1' => 'MN', 'code2' => 'MNG', 'nationalityName' => 'Mongolian'],
            ['name' => 'Montenegro', 'code1' => 'ME', 'code2' => 'MNE', 'nationalityName' => 'Montenegrin'],
            ['name' => 'Morocco', 'code1' => 'MA', 'code2' => 'MAR', 'nationalityName' => 'Moroccan'],
            ['name' => 'Mozambique', 'code1' => 'MZ', 'code2' => 'MOZ', 'nationalityName' => 'Mozambican'],
            ['name' => 'Myanmar', 'code1' => 'MM', 'code2' => 'MMR', 'nationalityName' => 'Burmese'],
            ['name' => 'Namibia', 'code1' => 'NA', 'code2' => 'NAM', 'nationalityName' => 'Namibian'],
            ['name' => 'Nauru', 'code1' => 'NR', 'code2' => 'NRU', 'nationalityName' => 'Nauruan'],
            ['name' => 'Nepal', 'code1' => 'NP', 'code2' => 'NPL', 'nationalityName' => 'Nepalese'],
            ['name' => 'Netherlands', 'code1' => 'NL', 'code2' => 'NLD', 'nationalityName' => 'Dutch'],
            ['name' => 'New Zealand', 'code1' => 'NZ', 'code2' => 'NZL', 'nationalityName' => 'New Zealander'],
            ['name' => 'Nicaragua', 'code1' => 'NI', 'code2' => 'NIC', 'nationalityName' => 'Nicaraguan'],
            ['name' => 'Niger', 'code1' => 'NE', 'code2' => 'NER', 'nationalityName' => 'Nigerien'],
            ['name' => 'Nigeria', 'code1' => 'NG', 'code2' => 'NGA', 'nationalityName' => 'Nigerian'],
            ['name' => 'North Korea', 'code1' => 'KP', 'code2' => 'PRK', 'nationalityName' => 'North Korean'],
            ['name' => 'North Macedonia', 'code1' => 'MK', 'code2' => 'MKD', 'nationalityName' => 'Macedonian'],
            ['name' => 'Norway', 'code1' => 'NO', 'code2' => 'NOR', 'nationalityName' => 'Norwegian'],
            ['name' => 'Oman', 'code1' => 'OM', 'code2' => 'OMN', 'nationalityName' => 'Omani'],
            ['name' => 'Pakistan', 'code1' => 'PK', 'code2' => 'PAK', 'nationalityName' => 'Pakistani'],
            ['name' => 'Palau', 'code1' => 'PW', 'code2' => 'PLW', 'nationalityName' => 'Palauan'],
            ['name' => 'Palestine', 'code1' => 'PS', 'code2' => 'PSE', 'nationalityName' => 'Palestinian'],
            ['name' => 'Panama', 'code1' => 'PA', 'code2' => 'PAN', 'nationalityName' => 'Panamanian'],
            ['name' => 'Papua New Guinea', 'code1' => 'PG', 'code2' => 'PNG', 'nationalityName' => 'Papua New Guinean'],
            ['name' => 'Paraguay', 'code1' => 'PY', 'code2' => 'PRY', 'nationalityName' => 'Paraguayan'],
            ['name' => 'Peru', 'code1' => 'PE', 'code2' => 'PER', 'nationalityName' => 'Peruvian'],
            ['name' => 'Philippines', 'code1' => 'PH', 'code2' => 'PHL', 'nationalityName' => 'Filipino'],
            ['name' => 'Poland', 'code1' => 'PL', 'code2' => 'POL', 'nationalityName' => 'Polish'],
            ['name' => 'Portugal', 'code1' => 'PT', 'code2' => 'PRT', 'nationalityName' => 'Portuguese'],
            ['name' => 'Qatar', 'code1' => 'QA', 'code2' => 'QAT', 'nationalityName' => 'Qatari'],
            ['name' => 'Romania', 'code1' => 'RO', 'code2' => 'ROU', 'nationalityName' => 'Romanian'],
            ['name' => 'Russia', 'code1' => 'RU', 'code2' => 'RUS', 'nationalityName' => 'Russian'],
            ['name' => 'Rwanda', 'code1' => 'RW', 'code2' => 'RWA', 'nationalityName' => 'Rwandan'],
            ['name' => 'Saint Kitts and Nevis', 'code1' => 'KN', 'code2' => 'KNA', 'nationalityName' => 'Kittitian'],
            ['name' => 'Saint Lucia', 'code1' => 'LC', 'code2' => 'LCA', 'nationalityName' => 'Saint Lucian'],
            ['name' => 'Saint Vincent and the Grenadines', 'code1' => 'VC', 'code2' => 'VCT', 'nationalityName' => 'Vincentian'],
            ['name' => 'Samoa', 'code1' => 'WS', 'code2' => 'WSM', 'nationalityName' => 'Samoan'],
            ['name' => 'San Marino', 'code1' => 'SM', 'code2' => 'SMR', 'nationalityName' => 'Sammarinese'],
            ['name' => 'Sao Tome and Principe', 'code1' => 'ST', 'code2' => 'STP', 'nationalityName' => 'Sao Tomean'],
            ['name' => 'Saudi Arabia', 'code1' => 'SA', 'code2' => 'SAU', 'nationalityName' => 'Saudi Arabian'],
            ['name' => 'Senegal', 'code1' => 'SN', 'code2' => 'SEN', 'nationalityName' => 'Senegalese'],
            ['name' => 'Serbia', 'code1' => 'RS', 'code2' => 'SRB', 'nationalityName' => 'Serbian'],
            ['name' => 'Seychelles', 'code1' => 'SC', 'code2' => 'SYC', 'nationalityName' => 'Seychellois'],
            ['name' => 'Sierra Leone', 'code1' => 'SL', 'code2' => 'SLE', 'nationalityName' => 'Sierra Leonean'],
            ['name' => 'Singapore', 'code1' => 'SG', 'code2' => 'SGP', 'nationalityName' => 'Singaporean'],
            ['name' => 'Slovakia', 'code1' => 'SK', 'code2' => 'SVK', 'nationalityName' => 'Slovak'],
            ['name' => 'Slovenia', 'code1' => 'SI', 'code2' => 'SVN', 'nationalityName' => 'Slovenian'],
            ['name' => 'Solomon Islands', 'code1' => 'SB', 'code2' => 'SLB', 'nationalityName' => 'Solomon Islander'],
            ['name' => 'Somalia', 'code1' => 'SO', 'code2' => 'SOM', 'nationalityName' => 'Somali'],
            ['name' => 'South Africa', 'code1' => 'ZA', 'code2' => 'ZAF', 'nationalityName' => 'South African'],
            ['name' => 'South Korea', 'code1' => 'KR', 'code2' => 'KOR', 'nationalityName' => 'South Korean'],
            ['name' => 'South Sudan', 'code1' => 'SS', 'code2' => 'SSD', 'nationalityName' => 'South Sudanese'],
            ['name' => 'Spain', 'code1' => 'ES', 'code2' => 'ESP', 'nationalityName' => 'Spanish'],
            ['name' => 'Sri Lanka', 'code1' => 'LK', 'code2' => 'LKA', 'nationalityName' => 'Sri Lankan'],
            ['name' => 'Sudan', 'code1' => 'SD', 'code2' => 'SDN', 'nationalityName' => 'Sudanese'],
            ['name' => 'Suriname', 'code1' => 'SR', 'code2' => 'SUR', 'nationalityName' => 'Surinamese'],
            ['name' => 'Sweden', 'code1' => 'SE', 'code2' => 'SWE', 'nationalityName' => 'Swedish'],
            ['name' => 'Switzerland', 'code1' => 'CH', 'code2' => 'CHE', 'nationalityName' => 'Swiss'],
            ['name' => 'Syria', 'code1' => 'SY', 'code2' => 'SYR', 'nationalityName' => 'Syrian'],
            ['name' => 'Taiwan', 'code1' => 'TW', 'code2' => 'TWN', 'nationalityName' => 'Taiwanese'],
            ['name' => 'Tajikistan', 'code1' => 'TJ', 'code2' => 'TJK', 'nationalityName' => 'Tajikistani'],
            ['name' => 'Tanzania', 'code1' => 'TZ', 'code2' => 'TZA', 'nationalityName' => 'Tanzanian'],
            ['name' => 'Thailand', 'code1' => 'TH', 'code2' => 'THA', 'nationalityName' => 'Thai'],
            ['name' => 'Timor-Leste', 'code1' => 'TL', 'code2' => 'TLS', 'nationalityName' => 'Timorese'],
            ['name' => 'Togo', 'code1' => 'TG', 'code2' => 'TGO', 'nationalityName' => 'Togolese'],
            ['name' => 'Tonga', 'code1' => 'TO', 'code2' => 'TON', 'nationalityName' => 'Tongan'],
            ['name' => 'Trinidad and Tobago', 'code1' => 'TT', 'code2' => 'TTO', 'nationalityName' => 'Trinidadian'],
            ['name' => 'Tunisia', 'code1' => 'TN', 'code2' => 'TUN', 'nationalityName' => 'Tunisian'],
            ['name' => 'Turkey', 'code1' => 'TR', 'code2' => 'TUR', 'nationalityName' => 'Turkish'],
            ['name' => 'Turkmenistan', 'code1' => 'TM', 'code2' => 'TKM', 'nationalityName' => 'Turkmen'],
            ['name' => 'Tuvalu', 'code1' => 'TV', 'code2' => 'TUV', 'nationalityName' => 'Tuvaluan'],
            ['name' => 'Uganda', 'code1' => 'UG', 'code2' => 'UGA', 'nationalityName' => 'Ugandan'],
            ['name' => 'Ukraine', 'code1' => 'UA', 'code2' => 'UKR', 'nationalityName' => 'Ukrainian'],
            ['name' => 'United Arab Emirates', 'code1' => 'AE', 'code2' => 'ARE', 'nationalityName' => 'Emirati'],
            ['name' => 'United Kingdom', 'code1' => 'GB', 'code2' => 'GBR', 'nationalityName' => 'British'],
            ['name' => 'United States', 'code1' => 'US', 'code2' => 'USA', 'nationalityName' => 'American'],
            ['name' => 'Uruguay', 'code1' => 'UY', 'code2' => 'URY', 'nationalityName' => 'Uruguayan'],
            ['name' => 'Uzbekistan', 'code1' => 'UZ', 'code2' => 'UZB', 'nationalityName' => 'Uzbekistani'],
            ['name' => 'Vanuatu', 'code1' => 'VU', 'code2' => 'VUT', 'nationalityName' => 'Ni-Vanuatu'],
            ['name' => 'Vatican City', 'code1' => 'VA', 'code2' => 'VAT', 'nationalityName' => 'Vatican'],
            ['name' => 'Venezuela', 'code1' => 'VE', 'code2' => 'VEN', 'nationalityName' => 'Venezuelan'],
            ['name' => 'Vietnam', 'code1' => 'VN', 'code2' => 'VNM', 'nationalityName' => 'Vietnamese'],
            ['name' => 'Yemen', 'code1' => 'YE', 'code2' => 'YEM', 'nationalityName' => 'Yemeni'],
            ['name' => 'Zambia', 'code1' => 'ZM', 'code2' => 'ZMB', 'nationalityName' => 'Zambian'],
            ['name' => 'Zimbabwe', 'code1' => 'ZW', 'code2' => 'ZWE', 'nationalityName' => 'Zimbabwean'],
        ];

        try {
            // Try API first
            $url = 'https://restcountries.com/v3.1/all';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 (compatible; Laravel Seeder)'
                ]
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response !== false) {
                $countriesData = json_decode($response, true);

                if ($countriesData && is_array($countriesData)) {
                    Country::truncate();

                    $seeded = 0;
                    foreach ($countriesData as $country) {
                        $payload = $this->normalizeCountryPayload(
                            $country['name']['common'] ?? null,
                            $country['cca2'] ?? null,
                            $country['cca3'] ?? null,
                            $country['demonyms']['eng']['m'] ?? ($country['demonyms']['eng']['f'] ?? null)
                        );

                        if ($payload === null) {
                            continue;
                        }

                        Country::create($payload);
                        $seeded++;
                    }

                    if ($seeded > 0) {
                        echo "Countries seeded successfully from API ({$seeded} records)!\n";
                        return;
                    }

                    echo "API returned no valid countries, using fallback data...\n";
                }
            }

            // Fallback to static data
            echo "API failed, using fallback data for countries...\n";
            $this->seedStaticCountries($countries);

        } catch (\Exception $e) {
            echo "Error while seeding countries: " . $e->getMessage() . "\n";

            if (Country::count() === 0) {
                $this->seedStaticCountries($countries);
            }
        }
    }

    private function normalizeCountryPayload(?string $name, ?string $code1, ?string $code2, ?string $nationalityName): ?array
    {
        $name = trim((string) $name);
        $code1 = strtoupper(trim((string) $code1));
        $code2 = strtoupper(trim((string) $code2));
        $nationalityName = trim((string) $nationalityName);

        if ($name === '' || strlen($code1) !== 2 || strlen($code2) !== 3) {
            return null;
        }

        return [
            'id' => Str::uuid(),
            'name' => $name,
            'code1' => $code1,
            'code2' => $code2,
            'nationalityName' => $nationalityName !== '' ? $nationalityName : $name,
        ];
    }

    private function seedStaticCountries(array $countries): void
    {
        Country::truncate();

        foreach ($countries as $country) {
            Country::create([
                'id' => Str::uuid(),
                'name' => $country['name'],
                'code1' => $country['code1'],
                'code2' => $country['code2'],
                'nationalityName' => $country['nationalityName'],
            ]);
        }

        echo "Countries seeded successfully with fallback data!\n";
    }
}

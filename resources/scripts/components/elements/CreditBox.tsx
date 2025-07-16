// make me a credit box that shows the user's credits and a button to buy more credits

import { faCoins } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import React from 'react';
import { Link } from 'react-router-dom';

const CreditBox = ({ credits }: { credits: number }) => {
    return (
        <Link
            to={'/account/credits'}
            className="credit-box navigation-link"
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: '0.5rem',
                padding: '0.5rem 1rem',
                fontSize: '1rem',
                fontWeight: 600,
            }}
        >
            <FontAwesomeIcon icon={faCoins} />
            <span>{credits}</span>
        </Link>
    );
};

export default CreditBox;